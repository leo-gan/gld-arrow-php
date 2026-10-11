<?php

declare(strict_types=1);

namespace Gld\Arrow\Wire;

use Gld\Arrow\Runtime\ArrowException;
use Gld\Arrow\Runtime\Columnar;
use Gld\Arrow\Runtime\FBBuilder;
use Gld\Arrow\Runtime\FBReader;
use Gld\Arrow\Runtime\Field;
use Gld\Arrow\Runtime\Kind;
use Gld\Arrow\Runtime\Layout;
use Gld\Arrow\Runtime\Limits;
use Gld\Arrow\Runtime\Type;

final class SchemaFb
{
    public static function encode(Columnar $c): string
    {
        $b = new FBBuilder();
        return $b->finish(self::schema($b, $c));
    }

    public static function schema(FBBuilder $b, Columnar $c): int
    {
        $offs = [];
        foreach ($c->top as $id) {
            $offs[] = self::field($b, $c, $id);
        }
        $fields = $b->writeOffsetVec($offs);
        $meta = self::metaVec($b, $c->metadata);
        $bits = 0;
        if ($c->codec >= 0) {
            $bits |= 2;
        }
        foreach ($c->batches as $batch) {
            if ($batch->codec >= 0) {
                $bits |= 2;
            }
        }
        $feats = self::features($b, $bits);
        $b->startTable(4);
        if ($c->endian !== 0) {
            $b->addU16(0, $c->endian, 0);
        }
        $b->addOffset(1, $fields);
        if ($meta >= 0) {
            $b->addOffset(2, $meta);
        }
        if ($feats >= 0) {
            $b->addOffset(3, $feats);
        }
        return $b->endTable();
    }

    private static function features(FBBuilder $b, int $bits): int
    {
        $vals = [];
        if (($bits & 1) !== 0) {
            $vals[] = 1;
        }
        if (($bits & 2) !== 0) {
            $vals[] = 2;
        }
        if ($vals === []) {
            return -1;
        }
        return $b->writeU64Vec($vals);
    }

    /** @param list<array{0:string,1:string}> $pairs */
    private static function metaVec(FBBuilder $b, array $pairs): int
    {
        if ($pairs === []) {
            return -1;
        }
        $offs = [];
        foreach ($pairs as $pair) {
            $k = $b->writeString($pair[0]);
            $v = $b->writeString($pair[1]);
            $b->startTable(2);
            $b->addOffset(0, $k);
            $b->addOffset(1, $v);
            $offs[] = $b->endTable();
        }
        return $b->writeOffsetVec($offs);
    }

    public static function field(FBBuilder $b, Columnar $c, int $id): int
    {
        $f = $c->fields[$id];
        $name = $f->name !== '' ? $b->writeString($f->name) : -1;
        $ty = self::typeTable($b, $f);
        $dict = self::dict($b, $f);
        $children = -1;
        if ($f->children !== []) {
            $offs = [];
            foreach ($f->children as $cid) {
                $offs[] = self::field($b, $c, $cid);
            }
            $children = $b->writeOffsetVec($offs);
        }
        $meta = self::metaVec($b, $f->metadata);
        $code = Layout::code($f->type);
        if ($code === 0) {
            throw new ArrowException(Kind::TYPE, $f->type);
        }
        $b->startTable(7);
        if ($name >= 0) {
            $b->addOffset(0, $name);
        }
        $b->addBool(1, $f->nullable, false);
        $b->addU8(2, $code, 0);
        $b->addOffset(3, $ty);
        if ($dict >= 0) {
            $b->addOffset(4, $dict);
        }
        if ($children >= 0) {
            $b->addOffset(5, $children);
        }
        if ($meta >= 0) {
            $b->addOffset(6, $meta);
        }
        return $b->endTable();
    }

    private static function dict(FBBuilder $b, Field $f): int
    {
        if ($f->dictId < 0) {
            return -1;
        }
        $b->startTable(2);
        $b->addI32(0, $f->dictIndexWidth, 0);
        $b->addBool(1, $f->dictIndexSigned, false);
        $index = $b->endTable();
        $b->startTable(4);
        $b->addI64(0, $f->dictId, -1);
        $b->addOffset(1, $index);
        $b->addBool(2, $f->dictOrdered, false);
        return $b->endTable();
    }

    public static function typeTable(FBBuilder $b, Field $f): int
    {
        switch ($f->type) {
            case Type::T_INT:
                $b->startTable(2);
                $b->addI32(0, $f->bitWidth, 0);
                $b->addBool(1, $f->signed, false);
                return $b->endTable();
            case Type::T_FLOAT:
                $b->startTable(1);
                $b->addU16(0, $f->unit, 0);
                return $b->endTable();
            case Type::T_DECIMAL:
                $b->startTable(3);
                $b->addI32(0, $f->precision, 0);
                $b->addI32(1, $f->scale, 0);
                $b->addI32(2, $f->bitWidth === 0 ? 128 : $f->bitWidth, 128);
                return $b->endTable();
            case Type::T_DATE:
                $b->startTable(1);
                $b->addU16(0, $f->unit, 1);
                return $b->endTable();
            case Type::T_TIME:
                $b->startTable(2);
                $b->addU16(0, $f->unit, 1);
                $b->addI32(1, $f->bitWidth === 0 ? 32 : $f->bitWidth, 32);
                return $b->endTable();
            case Type::T_TIMESTAMP:
                $tz = $f->timezone !== '' ? $b->writeString($f->timezone) : -1;
                $b->startTable(2);
                $b->addU16(0, $f->unit, 0);
                if ($tz >= 0) {
                    $b->addOffset(1, $tz);
                }
                return $b->endTable();
            case Type::T_INTERVAL:
                $b->startTable(1);
                $b->addU16(0, $f->unit, 0);
                return $b->endTable();
            case Type::T_DURATION:
                $b->startTable(1);
                $b->addU16(0, $f->unit, 1);
                return $b->endTable();
            case Type::T_FIXED_BINARY:
            case Type::T_FIXED_LIST:
                $b->startTable(1);
                $b->addI32(0, $f->fixedSize, 0);
                return $b->endTable();
            case Type::T_MAP:
                $b->startTable(1);
                $b->addBool(0, $f->keysSorted, false);
                return $b->endTable();
            case Type::T_UNION:
                $ids = -1;
                if ($f->typeIds !== []) {
                    $ids = $b->writeU32Vec($f->typeIds);
                }
                $b->startTable(2);
                $b->addU16(0, $f->unionMode, 0);
                if ($ids >= 0) {
                    $b->addOffset(1, $ids);
                }
                return $b->endTable();
            default:
                $b->startTable(0);
                return $b->endTable();
        }
    }

    public static function decode(FBReader $r, int $table, Columnar $c): void
    {
        $endp = $r->field($table, 0);
        $c->endian = $endp >= 0 ? $r->u16($endp) : 0;
        $fp = $r->field($table, 1);
        if ($fp < 0) {
            throw new ArrowException(Kind::SCHEMA, $table);
        }
        $n = $r->vecLen($fp);
        $data = $r->vecData($fp);
        for ($i = 0; $i < $n; $i++) {
            $rel = $r->u32($data + $i * 4);
            $c->top[] = self::readField($r, $data + $i * 4 + $rel, $c, 0);
        }
        $mp = $r->field($table, 2);
        if ($mp >= 0) {
            $c->metadata = self::readMeta($r, $mp);
        }
        $feat = $r->field($table, 3);
        if ($feat >= 0) {
            $n = $r->vecLen($feat);
            $data = $r->vecData($feat);
            for ($i = 0; $i < $n; $i++) {
                $v = $r->i64($data + $i * 8);
                if ($v === 1 || $v === 2) {
                    // retained only as a signal that compression may appear
                    if ($v === 2 && $c->codec < 0) {
                        $c->codec = -1;
                    }
                }
            }
        }
    }

    /** @return list<array{0:string,1:string}> */
    private static function readMeta(FBReader $r, int $pos): array
    {
        $n = $r->vecLen($pos);
        $data = $r->vecData($pos);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $rel = $r->u32($data + $i * 4);
            $kv = $data + $i * 4 + $rel;
            $k = '';
            $v = '';
            $kp = $r->field($kv, 0);
            if ($kp >= 0) {
                $k = $r->stringAt($kp);
            }
            $vp = $r->field($kv, 1);
            if ($vp >= 0) {
                $v = $r->stringAt($vp);
            }
            $out[] = [$k, $v];
        }
        return $out;
    }

    public static function readField(FBReader $r, int $table, Columnar $c, int $depth): int
    {
        if ($depth > Limits::DEPTH) {
            throw new ArrowException(Kind::DEPTH, $table);
        }
        $f = new Field();
        $np = $r->field($table, 0);
        if ($np >= 0) {
            $f->name = $r->stringAt($np);
        }
        $nul = $r->field($table, 1);
        $f->nullable = $nul >= 0 && $r->u8($nul) !== 0;
        $tp = $r->field($table, 2);
        if ($tp < 0) {
            throw new ArrowException(Kind::TYPE, $table);
        }
        $f->type = Layout::fromCode($r->u8($tp));
        $typ = $r->field($table, 3);
        if ($typ < 0) {
            throw new ArrowException(Kind::TYPE, $table);
        }
        self::readType($r, $r->follow($typ), $f);
        $dp = $r->field($table, 4);
        if ($dp >= 0) {
            self::readDict($r, $r->follow($dp), $f);
        }
        $id = $c->addField($f);
        $cp = $r->field($table, 5);
        $kids = [];
        if ($cp >= 0) {
            $n = $r->vecLen($cp);
            $data = $r->vecData($cp);
            for ($i = 0; $i < $n; $i++) {
                $rel = $r->u32($data + $i * 4);
                $kids[] = self::readField($r, $data + $i * 4 + $rel, $c, $depth + 1);
            }
        }
        $mp = $r->field($table, 6);
        if ($mp >= 0) {
            $f->metadata = self::readMeta($r, $mp);
        }
        $f->children = $kids;
        return $id;
    }

    private static function readDict(FBReader $r, int $table, Field $f): void
    {
        $ip = $r->field($table, 0);
        $f->dictId = $ip >= 0 ? $r->i64($ip) : 0;
        $tp = $r->field($table, 1);
        if ($tp >= 0) {
            $it = $r->follow($tp);
            $w = $r->field($it, 0);
            if ($w >= 0) {
                $f->dictIndexWidth = $r->i32($w);
            }
            $s = $r->field($it, 1);
            $f->dictIndexSigned = $s >= 0 && $r->u8($s) !== 0;
        }
        $op = $r->field($table, 2);
        $f->dictOrdered = $op >= 0 && $r->u8($op) !== 0;
    }

    public static function readType(FBReader $r, int $table, Field $f): void
    {
        switch ($f->type) {
            case Type::T_INT:
                $w = $r->field($table, 0);
                if ($w < 0) {
                    throw new ArrowException(Kind::TYPE, $table);
                }
                $f->bitWidth = $r->i32($w);
                $s = $r->field($table, 1);
                $f->signed = $s >= 0 && $r->u8($s) !== 0;
                return;
            case Type::T_FLOAT:
                $p = $r->field($table, 0);
                $f->unit = $p >= 0 ? $r->u16($p) : 0;
                return;
            case Type::T_DECIMAL:
                $p = $r->field($table, 0);
                if ($p >= 0) {
                    $f->precision = $r->i32($p);
                }
                $s = $r->field($table, 1);
                if ($s >= 0) {
                    $f->scale = $r->i32($s);
                }
                $w = $r->field($table, 2);
                $f->bitWidth = $w >= 0 ? $r->i32($w) : 128;
                return;
            case Type::T_DATE:
                $u = $r->field($table, 0);
                $f->unit = $u >= 0 ? $r->u16($u) : 1;
                return;
            case Type::T_TIME:
                $u = $r->field($table, 0);
                $f->unit = $u >= 0 ? $r->u16($u) : 1;
                $w = $r->field($table, 1);
                $f->bitWidth = $w >= 0 ? $r->i32($w) : 32;
                return;
            case Type::T_TIMESTAMP:
                $u = $r->field($table, 0);
                if ($u >= 0) {
                    $f->unit = $r->u16($u);
                }
                $z = $r->field($table, 1);
                if ($z >= 0) {
                    $f->timezone = $r->stringAt($z);
                }
                return;
            case Type::T_INTERVAL:
                $u = $r->field($table, 0);
                if ($u >= 0) {
                    $f->unit = $r->u16($u);
                }
                return;
            case Type::T_DURATION:
                $u = $r->field($table, 0);
                $f->unit = $u >= 0 ? $r->u16($u) : 1;
                return;
            case Type::T_FIXED_BINARY:
            case Type::T_FIXED_LIST:
                $w = $r->field($table, 0);
                if ($w >= 0) {
                    $f->fixedSize = $r->i32($w);
                }
                return;
            case Type::T_MAP:
                $s = $r->field($table, 0);
                $f->keysSorted = $s >= 0 && $r->u8($s) !== 0;
                return;
            case Type::T_UNION:
                $m = $r->field($table, 0);
                if ($m >= 0) {
                    $f->unionMode = $r->u16($m);
                }
                $ids = $r->field($table, 1);
                if ($ids >= 0) {
                    $n = $r->vecLen($ids);
                    $data = $r->vecData($ids);
                    for ($i = 0; $i < $n; $i++) {
                        $f->typeIds[] = $r->i32($data + $i * 4);
                    }
                }
                return;
        }
    }
}
