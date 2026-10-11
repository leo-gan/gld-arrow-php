<?php

declare(strict_types=1);

namespace Gld\Arrow\Wire;

use Gld\Arrow\Runtime\ArrowException;
use Gld\Arrow\Runtime\Bin;
use Gld\Arrow\Runtime\Columnar;
use Gld\Arrow\Runtime\FBBuilder;
use Gld\Arrow\Runtime\FBReader;
use Gld\Arrow\Runtime\Field;
use Gld\Arrow\Runtime\Kind;
use Gld\Arrow\Runtime\Layout;
use Gld\Arrow\Runtime\Meta;
use Gld\Arrow\Runtime\Tensor;

final class TensorIo
{
    public static function write(Columnar $c, int $version = Meta::V5): string
    {
        $out = '';
        foreach ($c->tensors as $i => $_) {
            $out .= self::encodeOne($c, $i, $version);
        }
        return $out;
    }

    public static function read(Columnar $c, FBReader $r, int $header, string $body, bool $sparse): void
    {
        if ($sparse) {
            self::readSparse($c, $r, $header, $body);
        } else {
            self::readDense($c, $r, $header, $body);
        }
    }

    private static function encodeOne(Columnar $c, int $index, int $version): string
    {
        $t = $c->tensors[$index];
        $body = '';
        $dataLoc = self::appendBody($body, $c, $t->dataBuf);
        $indexLocs = [];
        foreach ($t->indexBufs as $bid) {
            $indexLocs[] = self::appendBody($body, $c, $bid);
        }
        $mis = strlen($body) % 8;
        if ($mis !== 0) {
            $body .= str_repeat("\0", 8 - $mis);
        }
        $b = new FBBuilder();
        $f = $c->fields[$t->field];
        $ty = SchemaFb::typeTable($b, $f);
        $shape = self::shapeVec($b, $t);
        if ($t->sparseKind === 0) {
            $strides = -1;
            $vals = $t->strides !== [] ? $t->strides : self::rowMajor($f, $t->shape);
            if ($vals !== []) {
                $strides = $b->writeU64Vec($vals);
            }
            $b->startTable(5);
            $b->addU8(0, Layout::code($f->type), 0);
            $b->addOffset(1, $ty);
            $b->addOffset(2, $shape);
            if ($strides >= 0) {
                $b->addOffset(3, $strides);
            }
            $b->addI64Pair(4, $dataLoc[0], $dataLoc[1]);
            $header = $b->endTable();
            $kind = 4;
        } else {
            $indexTok = self::sparseIndex($b, $t, $indexLocs);
            $b->startTable(7);
            $b->addU8(0, Layout::code($f->type), 0);
            $b->addOffset(1, $ty);
            $b->addOffset(2, $shape);
            $b->addI64(3, $t->nz, -1);
            $b->addU8(4, $t->sparseKind, 0);
            $b->addOffset(5, $indexTok);
            $b->addI64Pair(6, $dataLoc[0], $dataLoc[1]);
            $header = $b->endTable();
            $kind = 5;
        }
        $b->startTable(5);
        $b->addU16(0, $version, 0);
        $b->addU8(1, $kind, 0);
        $b->addOffset(2, $header);
        $b->addI64(3, strlen($body), 0);
        $meta = $b->finish($b->endTable());
        $mis = strlen($meta) % 8;
        if ($mis !== 0) {
            $meta .= str_repeat("\0", 8 - $mis);
        }
        return pack('V', 0xFFFFFFFF) . pack('V', strlen($meta)) . $meta . $body;
    }

    /** @return array{0:int,1:int} */
    private static function appendBody(string &$body, Columnar $c, int $buf): array
    {
        $mis = strlen($body) % 8;
        if ($mis !== 0) {
            $body .= str_repeat("\0", 8 - $mis);
        }
        $off = strlen($body);
        $src = $buf < 0 ? '' : $c->buf($buf);
        $body .= $src;
        return [$off, strlen($src)];
    }

    /** @param list<int> $shape @return list<int> */
    private static function rowMajor(Field $f, array $shape): array
    {
        $width = Layout::byteWidth($f);
        if ($width <= 0) {
            $width = 1;
        }
        $strides = array_fill(0, count($shape), 0);
        $prod = $width;
        for ($i = count($shape) - 1; $i >= 0; $i--) {
            $strides[$i] = $prod;
            $prod *= $shape[$i];
        }
        return $strides;
    }

    private static function shapeVec(FBBuilder $b, Tensor $t): int
    {
        $offs = [];
        foreach ($t->shape as $i => $size) {
            $name = -1;
            $label = $t->shapeNames[$i] ?? '';
            if ($label !== '') {
                $name = $b->writeString($label);
            }
            $b->startTable(2);
            $b->addI64(0, $size, -1);
            if ($name >= 0) {
                $b->addOffset(1, $name);
            }
            $offs[] = $b->endTable();
        }
        return $b->writeOffsetVec($offs);
    }

    /** @param list<array{0:int,1:int}> $locs */
    private static function sparseIndex(FBBuilder $b, Tensor $t, array $locs): int
    {
        $b->startTable(2);
        $b->addI32(0, $t->indexWidth, 0);
        $b->addBool(1, $t->indexSigned, false);
        $itype = $b->endTable();
        if ($t->sparseKind === 1) {
            $b->startTable(4);
            $b->addOffset(0, $itype);
            $b->addI64Pair(2, $locs[0][0], $locs[0][1]);
            $b->addBool(3, $t->cooCanonical, false);
            return $b->endTable();
        }
        if ($t->sparseKind === 2) {
            $b->startTable(5);
            $b->addU16(0, $t->csxAxis, 0);
            $b->addOffset(1, $itype);
            $b->addI64Pair(2, $locs[0][0], $locs[0][1]);
            $b->addOffset(3, $itype);
            $b->addI64Pair(4, $locs[1][0], $locs[1][1]);
            return $b->endTable();
        }
        $half = intdiv(count($locs), 2);
        $rawP = '';
        for ($i = 0; $i < $half; $i++) {
            $rawP .= pack('q', $locs[$i][0]) . pack('q', $locs[$i][1]);
        }
        $pvec = $b->writeStructVec($rawP, 16);
        $rawI = '';
        for ($i = $half; $i < count($locs); $i++) {
            $rawI .= pack('q', $locs[$i][0]) . pack('q', $locs[$i][1]);
        }
        $ivec = $b->writeStructVec($rawI, 16);
        $order = -1;
        if ($t->axisOrder !== []) {
            $order = $b->writeU32Vec($t->axisOrder);
        }
        $b->startTable(5);
        $b->addOffset(0, $itype);
        $b->addOffset(1, $pvec);
        $b->addOffset(2, $itype);
        $b->addOffset(3, $ivec);
        if ($order >= 0) {
            $b->addOffset(4, $order);
        }
        return $b->endTable();
    }

    private static function readDense(Columnar $c, FBReader $r, int $header, string $body): void
    {
        $t = new Tensor();
        $t->field = self::tensorType($c, $r, $header);
        $t->sparseKind = 0;
        self::readShape($c, $r, $header, $t);
        $sp = $r->field($header, 3);
        if ($sp >= 0) {
            $n = $r->vecLen($sp);
            $data = $r->vecData($sp);
            for ($i = 0; $i < $n; $i++) {
                $t->strides[] = $r->i64($data + $i * 8);
            }
        } else {
            $t->strides = self::rowMajor($c->fields[$t->field], $t->shape);
        }
        $dp = $r->field($header, 4);
        if ($dp < 0) {
            throw new ArrowException(Kind::SCHEMA, $header);
        }
        $t->dataBuf = self::copyStructBuf($c, $r, $dp, $body);
        $c->tensors[] = $t;
    }

    private static function readSparse(Columnar $c, FBReader $r, int $header, string $body): void
    {
        $t = new Tensor();
        $t->field = self::tensorType($c, $r, $header);
        self::readShape($c, $r, $header, $t);
        $nz = $r->field($header, 3);
        if ($nz >= 0) {
            $t->nz = $r->i64($nz);
        }
        $kt = $r->field($header, 4);
        $kp = $r->field($header, 5);
        if ($kt < 0 || $kp < 0) {
            throw new ArrowException(Kind::SCHEMA, $header);
        }
        $kind = $r->u8($kt);
        $idx = $r->follow($kp);
        $t->sparseKind = $kind;
        if ($kind === 1) {
            self::readCoo($c, $r, $idx, $body, $t);
        } elseif ($kind === 2) {
            self::readCsx($c, $r, $idx, $body, $t);
        } elseif ($kind === 3) {
            self::readCsf($c, $r, $idx, $body, $t);
        } else {
            throw new ArrowException(Kind::TYPE, $kind);
        }
        $dp = $r->field($header, 6);
        if ($dp < 0) {
            throw new ArrowException(Kind::SCHEMA, $header);
        }
        $t->dataBuf = self::copyStructBuf($c, $r, $dp, $body);
        $c->tensors[] = $t;
    }

    private static function tensorType(Columnar $c, FBReader $r, int $header): int
    {
        $tp = $r->field($header, 0);
        $tt = $r->field($header, 1);
        if ($tp < 0 || $tt < 0) {
            throw new ArrowException(Kind::TYPE, $header);
        }
        $f = new Field();
        $f->type = Layout::fromCode($r->u8($tp));
        $f->nullable = false;
        SchemaFb::readType($r, $r->follow($tt), $f);
        return $c->addField($f);
    }

    private static function readShape(Columnar $c, FBReader $r, int $header, Tensor $t): void
    {
        $sp = $r->field($header, 2);
        if ($sp < 0) {
            throw new ArrowException(Kind::SCHEMA, $header);
        }
        $n = $r->vecLen($sp);
        $data = $r->vecData($sp);
        for ($i = 0; $i < $n; $i++) {
            $rel = $r->u32($data + $i * 4);
            $dim = $data + $i * 4 + $rel;
            $sz = $r->field($dim, 0);
            if ($sz < 0) {
                throw new ArrowException(Kind::SCHEMA, $dim);
            }
            $t->shape[] = $r->i64($sz);
            $nm = $r->field($dim, 1);
            $t->shapeNames[] = $nm >= 0 ? $r->stringAt($nm) : '';
        }
    }

    private static function copyStructBuf(Columnar $c, FBReader $r, int $pos, string $body): int
    {
        $off = $r->i64($pos);
        $ln = $r->i64($pos + 8);
        if ($ln === 0) {
            return $c->addBuf('');
        }
        Bin::checkBuffer($ln);
        if ($off < 0 || $off + $ln > strlen($body)) {
            throw new ArrowException(Kind::RANGE, $off);
        }
        return $c->addBuf(substr($body, $off, $ln));
    }

    private static function readIntType(FBReader $r, int $table, Tensor $t): void
    {
        $w = $r->field($table, 0);
        if ($w >= 0) {
            $t->indexWidth = $r->i32($w);
        }
        $s = $r->field($table, 1);
        $t->indexSigned = $s >= 0 && $r->u8($s) !== 0;
    }

    private static function readCoo(Columnar $c, FBReader $r, int $table, string $body, Tensor $t): void
    {
        $tp = $r->field($table, 0);
        if ($tp < 0) {
            throw new ArrowException(Kind::SCHEMA, $table);
        }
        self::readIntType($r, $r->follow($tp), $t);
        $bp = $r->field($table, 2);
        if ($bp < 0) {
            throw new ArrowException(Kind::SCHEMA, $table);
        }
        $t->indexBufs[] = self::copyStructBuf($c, $r, $bp, $body);
        $flag = $r->field($table, 3);
        $t->cooCanonical = $flag >= 0 && $r->u8($flag) !== 0;
    }

    private static function readCsx(Columnar $c, FBReader $r, int $table, string $body, Tensor $t): void
    {
        $ax = $r->field($table, 0);
        if ($ax >= 0) {
            $t->csxAxis = $r->u16($ax);
        }
        $ip = $r->field($table, 1);
        if ($ip < 0) {
            throw new ArrowException(Kind::SCHEMA, $table);
        }
        self::readIntType($r, $r->follow($ip), $t);
        $ib = $r->field($table, 2);
        $xb = $r->field($table, 4);
        if ($ib < 0 || $xb < 0) {
            throw new ArrowException(Kind::SCHEMA, $table);
        }
        $t->indexBufs[] = self::copyStructBuf($c, $r, $ib, $body);
        $t->indexBufs[] = self::copyStructBuf($c, $r, $xb, $body);
    }

    private static function readCsf(Columnar $c, FBReader $r, int $table, string $body, Tensor $t): void
    {
        $ip = $r->field($table, 0);
        if ($ip < 0) {
            throw new ArrowException(Kind::SCHEMA, $table);
        }
        self::readIntType($r, $r->follow($ip), $t);
        $pb = $r->field($table, 1);
        if ($pb >= 0) {
            self::readBufVec($c, $r, $pb, $body, $t);
        }
        $ib = $r->field($table, 3);
        if ($ib >= 0) {
            self::readBufVec($c, $r, $ib, $body, $t);
        }
        $ao = $r->field($table, 4);
        if ($ao >= 0) {
            $n = $r->vecLen($ao);
            $data = $r->vecData($ao);
            for ($i = 0; $i < $n; $i++) {
                $t->axisOrder[] = $r->i32($data + $i * 4);
            }
        }
    }

    private static function readBufVec(Columnar $c, FBReader $r, int $pos, string $body, Tensor $t): void
    {
        $n = $r->vecLen($pos);
        $data = $r->vecData($pos);
        for ($i = 0; $i < $n; $i++) {
            $t->indexBufs[] = self::copyStructBuf($c, $r, $data + $i * 16, $body);
        }
    }
}
