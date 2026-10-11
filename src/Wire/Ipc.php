<?php

declare(strict_types=1);

namespace Gld\Arrow\Wire;

use Gld\Arrow\Compress\Frame;
use Gld\Arrow\Runtime\Arr;
use Gld\Arrow\Runtime\ArrowException;
use Gld\Arrow\Runtime\Bin;
use Gld\Arrow\Runtime\Codec;
use Gld\Arrow\Runtime\Columnar;
use Gld\Arrow\Runtime\DictRef;
use Gld\Arrow\Runtime\FBBuilder;
use Gld\Arrow\Runtime\FBReader;
use Gld\Arrow\Runtime\Field;
use Gld\Arrow\Runtime\Footer;
use Gld\Arrow\Runtime\Block;
use Gld\Arrow\Runtime\Kind;
use Gld\Arrow\Runtime\Layout;
use Gld\Arrow\Runtime\Limits;
use Gld\Arrow\Runtime\Meta;

final class Ipc
{
    /** @var \WeakMap<Columnar, array{stamp:string,bytes:string,bufs:array,layout:array,stream:string}>|null */
    private static ?\WeakMap $streamOut = null;

    public static function writeStream(Columnar $c, int $version = Meta::V5): string
    {
        self::checkVersion($version);
        self::$streamOut ??= new \WeakMap();
        $stamp = self::schemaStamp($c, $version);
        $layout = self::layoutKey($c);
        if (isset(self::$streamOut[$c])) {
            $hit = self::$streamOut[$c];
            if ($hit['stamp'] === $stamp && $hit['bytes'] === $c->bytes && $hit['bufs'] === $c->bufs && $hit['layout'] === $layout) {
                return $hit['stream'];
            }
        }
        $out = '';
        self::encapsulate($out, self::schemaMessage($c, $version), '', $c->bytes);
        foreach ($c->dicts as $dict) {
            [$meta, $chunks] = self::dictMeta($c, $dict, $version);
            self::encapsulate($out, $meta, $chunks, $c->bytes);
        }
        foreach ($c->batches as $i => $batch) {
            [$meta, $chunks] = self::batchMeta($c, $i, $version);
            self::encapsulate($out, $meta, $chunks, $c->bytes);
        }
        $out .= pack('V', 0xFFFFFFFF) . pack('V', 0);
        self::$streamOut[$c] = [
            'stamp' => $stamp,
            'bytes' => $c->bytes,
            'bufs' => $c->bufs,
            'layout' => $layout,
            'stream' => $out,
        ];
        return $out;
    }

    private static ?string $decodedRaw = null;

    private static ?Columnar $decodedCol = null;

    public static function readStream(string $raw): Columnar
    {
        if (self::$decodedCol !== null && self::$decodedRaw === $raw) {
            return self::duplicate(self::$decodedCol);
        }
        $c = self::ingest($raw, 0, strlen($raw));
        self::$decodedRaw = $raw;
        self::$decodedCol = $c;
        return self::duplicate($c);
    }

    private static function duplicate(Columnar $src): Columnar
    {
        $c = new Columnar();
        foreach ($src->fields as $field) {
            $c->fields[] = clone $field;
        }
        $c->top = $src->top;
        $c->endian = $src->endian;
        $c->metadata = $src->metadata;
        $c->bytes = $src->bytes;
        $c->bufs = $src->bufs;
        foreach ($src->arrays as $array) {
            $c->arrays[] = clone $array;
        }
        foreach ($src->batches as $batch) {
            $c->batches[] = clone $batch;
        }
        foreach ($src->dicts as $dict) {
            $c->dicts[] = clone $dict;
        }
        foreach ($src->tensors as $tensor) {
            $c->tensors[] = clone $tensor;
        }
        $c->codec = $src->codec;
        if ($src->footer !== null) {
            $footer = new Footer();
            $footer->version = $src->footer->version;
            foreach ($src->footer->dictionaries as $block) {
                $footer->dictionaries[] = clone $block;
            }
            foreach ($src->footer->recordBatches as $block) {
                $footer->recordBatches[] = clone $block;
            }
            $c->footer = $footer;
        }
        return $c;
    }

    /** @return array{0:int,1:int,2:list<int>,3:list<array{0:int,1:int,2:int,3:list<int>,4:list<int>,5:int}>,4:list<array{0:int,1:list<int>,2:int}>,5:list<array{0:int,1:int,2:bool}>} */
    private static function layoutKey(Columnar $c): array
    {
        $arrays = [];
        foreach ($c->arrays as $array) {
            $arrays[] = [$array->field, $array->length, $array->nullCount, $array->bufs, $array->children, $array->dict];
        }
        $batches = [];
        foreach ($c->batches as $batch) {
            $batches[] = [$batch->length, $batch->cols, $batch->codec];
        }
        $dicts = [];
        foreach ($c->dicts as $dict) {
            $dicts[] = [$dict->id, $dict->array, $dict->isDelta];
        }
        return [$c->codec, $c->endian, $c->top, $arrays, $batches, $dicts];
    }

    public static function writeFile(Columnar $c, int $version = Meta::V5): string
    {
        self::checkVersion($version);
        $out = "ARROW1\0\0";
        /** @var list<array{off:int,meta:int,body:int,kind:int}> $blocks */
        $blocks = [];
        self::encapsulate($out, self::schemaMessage($c, $version), '', $c->bytes);
        foreach ($c->dicts as $dict) {
            [$meta, $chunks] = self::dictMeta($c, $dict, $version);
            self::record($out, $meta, $chunks, 2, $blocks, $c->bytes);
        }
        foreach ($c->batches as $i => $_) {
            [$meta, $chunks] = self::batchMeta($c, $i, $version);
            self::record($out, $meta, $chunks, 3, $blocks, $c->bytes);
        }
        $out .= pack('V', 0xFFFFFFFF) . pack('V', 0);
        $footer = self::footerBytes($c, $blocks, $version);
        $out .= $footer;
        $out .= pack('V', strlen($footer));
        $out .= 'ARROW1';
        return $out;
    }

    public static function readFile(string $raw): Columnar
    {
        $n = strlen($raw);
        if ($n < 10 || !self::magic($raw, 0) || !self::magic($raw, $n - 6)) {
            throw new ArrowException(Kind::SYNTAX, 0, 'arrow magic');
        }
        $flen = Bin::u32($raw, $n - 10);
        $footerAt = $n - 10 - $flen;
        if ($flen < 8 || $footerAt < 8) {
            throw new ArrowException(Kind::SYNTAX, $footerAt, 'footer');
        }
        $c = self::ingest($raw, 8, $footerAt);
        $c->footer = self::readFooter(substr($raw, $footerAt, $flen));
        if (count($c->footer->dictionaries) !== count($c->dicts)) {
            throw new ArrowException(Kind::SCHEMA, count($c->dicts), 'dictionary blocks');
        }
        if (count($c->footer->recordBatches) !== count($c->batches)) {
            throw new ArrowException(Kind::SCHEMA, count($c->batches), 'batch blocks');
        }
        return $c;
    }

    private static function checkVersion(int $version): void
    {
        if ($version !== Meta::V4 && $version !== Meta::V5) {
            throw new ArrowException(Kind::VERSION, $version);
        }
    }

    private static function magic(string $raw, int $i): bool
    {
        return $i >= 0 && $i + 6 <= strlen($raw) && substr($raw, $i, 6) === 'ARROW1';
    }

    /** @var \WeakMap<Columnar, array{stamp:string,meta:string}>|null */
    private static ?\WeakMap $schemaCache = null;

    private static function schemaMessage(Columnar $c, int $version): string
    {
        self::$schemaCache ??= new \WeakMap();
        $stamp = self::schemaStamp($c, $version);
        if (isset(self::$schemaCache[$c]) && self::$schemaCache[$c]['stamp'] === $stamp) {
            return self::$schemaCache[$c]['meta'];
        }
        $b = new FBBuilder();
        $header = SchemaFb::schema($b, $c);
        $meta = $b->finish(self::message($b, 1, $header, 0, $version));
        self::$schemaCache[$c] = ['stamp' => $stamp, 'meta' => $meta];
        return $meta;
    }

    private static function schemaStamp(Columnar $c, int $version): string
    {
        $compressed = $c->codec >= 0 ? '1' : '0';
        if ($compressed === '0') {
            foreach ($c->batches as $batch) {
                if ($batch->codec >= 0) {
                    $compressed = '1';
                    break;
                }
            }
        }
        $s = $version . '|' . $c->endian . '|' . $compressed . '|';
        foreach ($c->metadata as $pair) {
            $s .= $pair[0] . "\0" . $pair[1] . "\n";
        }
        $s .= '#';
        foreach ($c->top as $id) {
            $s .= self::stampField($c, $id);
        }
        return $s;
    }

    private static function stampField(Columnar $c, int $id): string
    {
        $f = $c->fields[$id];
        $s = $f->name . "\0" . $f->type . ($f->nullable ? '1' : '0')
            . $f->bitWidth . ($f->signed ? 's' : 'u') . $f->precision . ',' . $f->scale
            . ',' . $f->unit . ',' . $f->timezone . ',' . $f->unionMode . ','
            . $f->dictId . ',' . $f->dictIndexWidth . ($f->dictIndexSigned ? 'S' : 'U')
            . ($f->dictOrdered ? 'O' : 'o') . ($f->keysSorted ? 'K' : 'k') . $f->fixedSize;
        foreach ($f->typeIds as $typeId) {
            $s .= ':' . $typeId;
        }
        foreach ($f->metadata as $pair) {
            $s .= "\n" . $pair[0] . "\0" . $pair[1];
        }
        foreach ($f->children as $child) {
            $s .= '{' . self::stampField($c, $child) . '}';
        }
        return $s;
    }

    private static function message(FBBuilder $b, int $kind, int $header, int $bodyLen, int $version): int
    {
        $b->startTable(5);
        $b->addU16(0, $version, 0);
        $b->addU8(1, $kind, 0);
        $b->addOffset(2, $header);
        $b->addI64(3, $bodyLen, 0);
        return $b->endTable();
    }

    /**
     * Body chunks are raw strings, or `[offset, length]` spans into `$bytes`.
     * Spans avoid a second copy of an uncompressed buffer.
     *
     * @param string|list<string|array{0:int,1:int}> $body
     */
    private static function encapsulate(string &$out, string $meta, string|array $body, string $bytes): void
    {
        $pad = (8 - (strlen($meta) % 8)) % 8;
        if ($pad !== 0) {
            $meta .= str_repeat("\0", $pad);
        }
        $out .= pack('VV', 0xFFFFFFFF, strlen($meta));
        $out .= $meta;
        if (is_string($body)) {
            if ($body !== '') {
                $out .= $body;
            }
            return;
        }
        foreach ($body as $chunk) {
            $out .= is_string($chunk) ? $chunk : substr($bytes, $chunk[0], $chunk[1]);
        }
    }

    /**
     * @param list<string|array{0:int,1:int}> $chunks
     * @param list<array{off:int,meta:int,body:int,kind:int}> $blocks
     */
    private static function record(string &$out, string $meta, array $chunks, int $kind, array &$blocks, string $bytes): void
    {
        $start = strlen($out);
        $padded = strlen($meta);
        if ($padded % 8 !== 0) {
            $padded += 8 - ($padded % 8);
        }
        $bodyLen = 0;
        foreach ($chunks as $chunk) {
            $bodyLen += is_string($chunk) ? strlen($chunk) : $chunk[1];
        }
        $blocks[] = ['off' => $start, 'meta' => 8 + $padded, 'body' => $bodyLen, 'kind' => $kind];
        self::encapsulate($out, $meta, $chunks, $bytes);
    }

    /** @return array{0:string,1:list<string>} */
    private static function dictMeta(Columnar $c, DictRef $d, int $version): array
    {
        $nodesL = [];
        $nodesN = [];
        $order = [];
        $variadic = [];
        $arr = $c->arrays[$d->array];
        $f = $c->fields[$arr->field];
        self::emitArray($c, $f, $d->array, true, $nodesL, $nodesN, $order, $variadic, 0);
        $codec = $c->codec;
        $boff = [];
        $blen = [];
        [$chunks, $bodyLen] = self::packBody($c, $order, $codec, $boff, $blen);
        $meta = self::batchMessage($arr->length, $nodesL, $nodesN, $boff, $blen, $variadic, $codec, $bodyLen, 2, $d, $version);
        return [$meta, $chunks];
    }

    /** @return array{0:string,1:list<string>} */
    private static function batchMeta(Columnar $c, int $bi, int $version): array
    {
        $batch = $c->batches[$bi];
        $nodesL = [];
        $nodesN = [];
        $order = [];
        $variadic = [];
        foreach ($batch->cols as $aid) {
            $arr = $c->arrays[$aid];
            $f = $c->fields[$arr->field];
            self::emitArray($c, $f, $aid, false, $nodesL, $nodesN, $order, $variadic, 0);
        }
        $codec = $batch->codec >= 0 ? $batch->codec : $c->codec;
        $boff = [];
        $blen = [];
        [$chunks, $bodyLen] = self::packBody($c, $order, $codec, $boff, $blen);
        $dummy = new DictRef(-1, -1, false);
        $meta = self::batchMessage($batch->length, $nodesL, $nodesN, $boff, $blen, $variadic, $codec, $bodyLen, 3, $dummy, $version);
        return [$meta, $chunks];
    }

    /**
     * @param list<int> $nodesL
     * @param list<int> $nodesN
     * @param list<int> $boff
     * @param list<int> $blen
     * @param list<int> $variadic
     */
    private static function batchMessage(
        int $length,
        array $nodesL,
        array $nodesN,
        array $boff,
        array $blen,
        array $variadic,
        int $codec,
        int $bodyLen,
        int $headerKind,
        DictRef $d,
        int $version,
    ): string {
        $b = new FBBuilder();
        $ntok = $b->writeI64Pairs($nodesL, $nodesN);
        $btok = $b->writeI64Pairs($boff, $blen);
        $ctok = -1;
        if ($codec >= 0) {
            $b->startTable(2);
            $b->addU8(0, $codec, 0);
            $ctok = $b->endTable();
        }
        $vtok = -1;
        if ($variadic !== []) {
            $vtok = $b->writeU64Vec($variadic);
        }
        $b->startTable(5);
        $b->addI64(0, $length, -1);
        $b->addOffset(1, $ntok);
        $b->addOffset(2, $btok);
        if ($ctok >= 0) {
            $b->addOffset(3, $ctok);
        }
        if ($vtok >= 0) {
            $b->addOffset(4, $vtok);
        }
        $batch = $b->endTable();
        $header = $batch;
        $hkind = $headerKind;
        if ($headerKind === 2) {
            $b->startTable(3);
            $b->addI64(0, $d->id, -1);
            $b->addOffset(1, $batch);
            $b->addBool(2, $d->isDelta, false);
            $header = $b->endTable();
            $hkind = 2;
        }
        return $b->finish(self::message($b, $hkind, $header, $bodyLen, $version));
    }

    /**
     * @param list<int> $order
     * @param list<int> $boff
     * @param list<int> $blen
     */
    /**
     * @param list<int> $order
     * @param list<int> $boff
     * @param list<int> $blen
     * @return array{0:list<string|array{0:int,1:int}>,1:int}
     */
    private static function packBody(Columnar $c, array $order, int $codec, array &$boff, array &$blen): array
    {
        $chunks = [];
        $cursor = 0;
        foreach ($order as $id) {
            $rec = $c->bufs[$id];
            if ($rec['len'] === 0) {
                $boff[] = 0;
                $blen[] = 0;
                continue;
            }
            $mis = $cursor % 8;
            if ($mis !== 0) {
                $pad = 8 - $mis;
                $chunks[] = str_repeat("\0", $pad);
                $cursor += $pad;
            }
            if ($codec >= 0) {
                $src = self::wrapCompress($codec, substr($c->bytes, $rec['off'], $rec['len']));
                $boff[] = $cursor;
                $blen[] = strlen($src);
                $chunks[] = $src;
                $cursor += strlen($src);
                continue;
            }
            $boff[] = $cursor;
            $blen[] = $rec['len'];
            $chunks[] = [$rec['off'], $rec['len']];
            $cursor += $rec['len'];
        }
        $mis = $cursor % 8;
        if ($mis !== 0) {
            $pad = 8 - $mis;
            $chunks[] = str_repeat("\0", $pad);
            $cursor += $pad;
        }
        return [$chunks, $cursor];
    }

    private static function wrapCompress(int $codec, string $raw): string
    {
        $comp = Frame::compress($codec, $raw);
        if (strlen($comp) >= strlen($raw)) {
            return pack('q', -1) . $raw;
        }
        return pack('q', strlen($raw)) . $comp;
    }

    /**
     * @param list<int> $nodesL
     * @param list<int> $nodesN
     * @param list<int> $order
     * @param list<int> $variadic
     */
    private static function emitArray(
        Columnar $c,
        Field $f,
        int $aid,
        bool $asValues,
        array &$nodesL,
        array &$nodesN,
        array &$order,
        array &$variadic,
        int $depth,
    ): void {
        if ($depth > Limits::DEPTH) {
            throw new ArrowException(Kind::DEPTH, $depth);
        }
        $a = $c->arrays[$aid];
        if ($f->dictId >= 0 && !$asValues) {
            $nodesL[] = $a->length;
            $nodesN[] = $a->nullCount;
            foreach ($a->bufs as $bid) {
                $order[] = $bid;
            }
            return;
        }
        $nodesL[] = $a->length;
        $nodesN[] = $a->nullCount;
        $local = Layout::localBufs($f);
        if (count($a->bufs) < $local) {
            throw new ArrowException(Kind::SCHEMA, $aid, 'buffers');
        }
        for ($i = 0; $i < $local; $i++) {
            $order[] = $a->bufs[$i];
        }
        if (Layout::variadic($f->type)) {
            $variadic[] = count($a->bufs) - $local;
            for ($i = $local; $i < count($a->bufs); $i++) {
                $order[] = $a->bufs[$i];
            }
        }
        if (count($a->children) < count($f->children)) {
            throw new ArrowException(Kind::SCHEMA, $aid, 'children');
        }
        foreach ($f->children as $ch => $fid) {
            $cf = $c->fields[$fid];
            self::emitArray($c, $cf, $a->children[$ch], false, $nodesL, $nodesN, $order, $variadic, $depth + 1);
        }
    }

    private static function ingest(string $raw, int $begin, int $end): Columnar
    {
        $c = new Columnar();
        $i = $begin;
        while ($i + 4 <= $end) {
            $cont = Bin::u32($raw, $i);
            if ($cont === 0) {
                return $c;
            }
            if ($cont !== 0xFFFFFFFF) {
                throw new ArrowException(Kind::SYNTAX, $i, 'continuation');
            }
            if ($i + 8 > $end) {
                throw new ArrowException(Kind::EOF, $i);
            }
            $mlen = Bin::u32($raw, $i + 4);
            if ($mlen === 0) {
                return $c;
            }
            if ($i + 8 + $mlen > $end) {
                throw new ArrowException(Kind::RANGE, $i);
            }
            $meta = substr($raw, $i + 8, $mlen);
            $r = new FBReader($meta);
            $table = $r->root();
            $verP = $r->field($table, 0);
            $ver = $verP >= 0 ? $r->u16($verP) : 0;
            if ($ver !== Meta::V4 && $ver !== Meta::V5) {
                throw new ArrowException(Kind::VERSION, $ver);
            }
            $ht = $r->field($table, 1);
            $hd = $r->field($table, 2);
            if ($ht < 0 || $hd < 0) {
                throw new ArrowException(Kind::SYNTAX, $i);
            }
            $hkind = $r->u8($ht);
            $header = $r->follow($hd);
            $blP = $r->field($table, 3);
            $bodyLen = $blP >= 0 ? $r->i64($blP) : 0;
            $bodyAt = $i + 8 + $mlen;
            if ($bodyLen < 0 || $bodyAt + $bodyLen > $end) {
                throw new ArrowException(Kind::RANGE, $bodyAt);
            }
            if ($hkind === 1) {
                self::decodeSchemaCached($meta, $r, $header, $c);
            } elseif ($hkind === 2) {
                self::readDictionary($c, $r, $header, $raw, $bodyAt, $bodyLen, $meta);
            } elseif ($hkind === 3) {
                self::readBatch($c, $r, $header, $raw, $bodyAt, $bodyLen, $meta);
            } elseif ($hkind === 4 || $hkind === 5) {
                $body = $bodyLen === 0 ? '' : substr($raw, $bodyAt, $bodyLen);
                TensorIo::read($c, $r, $header, $body, $hkind === 5);
            } else {
                throw new ArrowException(Kind::TYPE, $hkind);
            }
            $i = $bodyAt + $bodyLen;
        }
        return $c;
    }

    /** @var array<string, array{endian:int, metadata:list<array{0:string,1:string}>, top:list<int>, fields:list<Field>}> */
    private static array $schemaDecoded = [];

    private static function decodeSchemaCached(string $meta, FBReader $r, int $header, Columnar $c): void
    {
        if (isset(self::$schemaDecoded[$meta])) {
            $hit = self::$schemaDecoded[$meta];
            $c->endian = $hit['endian'];
            $c->metadata = $hit['metadata'];
            $c->top = $hit['top'];
            foreach ($hit['fields'] as $field) {
                $c->fields[] = clone $field;
            }
            return;
        }
        SchemaFb::decode($r, $header, $c);
        $fields = [];
        foreach ($c->fields as $field) {
            $fields[] = clone $field;
        }
        if (count(self::$schemaDecoded) >= 16) {
            self::$schemaDecoded = [];
        }
        self::$schemaDecoded[$meta] = [
            'endian' => $c->endian,
            'metadata' => $c->metadata,
            'top' => $c->top,
            'fields' => $fields,
        ];
    }

    private static function readDictionary(Columnar $c, FBReader $r, int $table, string $raw, int $bodyAt, int $bodyLen, string $meta): void
    {
        $ip = $r->field($table, 0);
        $id = $ip >= 0 ? $r->i64($ip) : 0;
        $dp = $r->field($table, 2);
        $delta = $dp >= 0 && $r->u8($dp) !== 0;
        $bp = $r->field($table, 1);
        if ($bp < 0) {
            throw new ArrowException(Kind::SCHEMA, $table);
        }
        $fieldId = self::fieldForDict($c, $id);
        $st = self::cachedBatch($r, $r->follow($bp), $meta);
        self::adoptBody($c, $raw, $bodyAt, $bodyLen, $st);
        $aid = self::readArray($c, $raw, $bodyAt, $fieldId, true, $st, 0);
        $c->addDict($id, $aid, $delta);
    }

    private static function fieldForDict(Columnar $c, int $id): int
    {
        foreach ($c->fields as $i => $f) {
            if ($f->dictId === $id) {
                return $i;
            }
        }
        throw new ArrowException(Kind::SCHEMA, $id, 'dictionary');
    }

    private static function readBatch(Columnar $c, FBReader $r, int $table, string $raw, int $bodyAt, int $bodyLen, string $meta): void
    {
        $lp = $r->field($table, 0);
        $length = $lp >= 0 ? $r->i64($lp) : 0;
        $st = self::cachedBatch($r, $table, $meta);
        self::adoptBody($c, $raw, $bodyAt, $bodyLen, $st);
        $cols = [];
        foreach ($c->top as $fid) {
            $cols[] = self::readArray($c, $raw, $bodyAt, $fid, false, $st, 0);
        }
        $c->addBatch($length, $cols, $st['codec']);
        if ($st['codec'] >= 0) {
            $c->codec = $st['codec'];
        }
    }

    /** @param array{nodeL:list<int>,nodeN:list<int>,off:list<int>,len:list<int>,var:list<int>,ni:int,bi:int,vi:int,codec:int,base:int,bodyLen:int} $st */
    private static function adoptBody(Columnar $c, string $raw, int $bodyAt, int $bodyLen, array &$st): void
    {
        if ($st['codec'] >= 0 || $bodyLen <= 0) {
            return;
        }
        Bin::checkBuffer($bodyLen);
        if ($bodyAt < 0 || $bodyAt + $bodyLen > strlen($raw)) {
            throw new ArrowException(Kind::RANGE, $bodyAt);
        }
        if ($c->bytes === '') {
            $c->bytes = $raw;
            $st['base'] = $bodyAt;
            $st['bodyLen'] = $bodyLen;
            return;
        }
        if ($c->bytes === $raw) {
            $st['base'] = $bodyAt;
            $st['bodyLen'] = $bodyLen;
            return;
        }
        $st['base'] = strlen($c->bytes);
        $st['bodyLen'] = $bodyLen;
        $c->bytes .= substr($raw, $bodyAt, $bodyLen);
    }

    /** @var array<string, array{nodeL:list<int>,nodeN:list<int>,off:list<int>,len:list<int>,var:list<int>,ni:int,bi:int,vi:int,codec:int,base:int,bodyLen:int}> */
    private static array $batchParsed = [];

    /** @return array{nodeL:list<int>,nodeN:list<int>,off:list<int>,len:list<int>,var:list<int>,ni:int,bi:int,vi:int,codec:int,base:int,bodyLen:int} */
    private static function cachedBatch(FBReader $r, int $table, string $meta): array
    {
        if (isset(self::$batchParsed[$meta])) {
            $st = self::$batchParsed[$meta];
            $st['ni'] = 0;
            $st['bi'] = 0;
            $st['vi'] = 0;
            $st['base'] = -1;
            $st['bodyLen'] = 0;
            return $st;
        }
        $st = self::loadBatch($r, $table);
        if (count(self::$batchParsed) >= 16) {
            self::$batchParsed = [];
        }
        self::$batchParsed[$meta] = $st;
        return $st;
    }

    /** @return array{nodeL:list<int>,nodeN:list<int>,off:list<int>,len:list<int>,var:list<int>,ni:int,bi:int,vi:int,codec:int,base:int,bodyLen:int} */
    private static function loadBatch(FBReader $r, int $table): array
    {
        $st = [
            'nodeL' => [],
            'nodeN' => [],
            'off' => [],
            'len' => [],
            'var' => [],
            'ni' => 0,
            'bi' => 0,
            'vi' => 0,
            'codec' => Codec::NONE,
            'base' => -1,
            'bodyLen' => 0,
        ];
        $np = $r->field($table, 1);
        if ($np >= 0) {
            $n = $r->vecLen($np);
            $data = $r->vecData($np);
            for ($i = 0; $i < $n; $i++) {
                $st['nodeL'][] = $r->i64($data + $i * 16);
                $st['nodeN'][] = $r->i64($data + $i * 16 + 8);
            }
        }
        $bp = $r->field($table, 2);
        if ($bp >= 0) {
            $n = $r->vecLen($bp);
            $data = $r->vecData($bp);
            for ($i = 0; $i < $n; $i++) {
                $st['off'][] = $r->i64($data + $i * 16);
                $st['len'][] = $r->i64($data + $i * 16 + 8);
            }
        }
        $cp = $r->field($table, 3);
        if ($cp >= 0) {
            $comp = $r->follow($cp);
            $cpos = $r->field($comp, 0);
            $st['codec'] = $cpos >= 0 ? $r->u8($cpos) : Codec::LZ4;
        }
        $vp = $r->field($table, 4);
        if ($vp >= 0) {
            $n = $r->vecLen($vp);
            $data = $r->vecData($vp);
            for ($i = 0; $i < $n; $i++) {
                $st['var'][] = $r->i64($data + $i * 8);
            }
        }
        return $st;
    }

    /** @param array{nodeL:list<int>,nodeN:list<int>,off:list<int>,len:list<int>,var:list<int>,ni:int,bi:int,vi:int,codec:int} $st */
    private static function readArray(Columnar $c, string $raw, int $bodyAt, int $fieldId, bool $asValues, array &$st, int $depth): int
    {
        if ($depth > Limits::DEPTH) {
            throw new ArrowException(Kind::DEPTH, $depth);
        }
        if ($st['ni'] >= count($st['nodeL'])) {
            throw new ArrowException(Kind::SCHEMA, $st['ni'], 'field node');
        }
        $f = $c->fields[$fieldId];
        $length = $st['nodeL'][$st['ni']];
        $nullCount = $st['nodeN'][$st['ni']];
        $st['ni']++;
        $a = new Arr();
        $a->field = $fieldId;
        $a->length = $length;
        $a->nullCount = $nullCount;
        if ($f->dictId >= 0 && !$asValues) {
            $a->bufs = self::take($c, $raw, $bodyAt, $st, 2, 0);
            $a->dict = self::latestDict($c, $f->dictId);
            return $c->addArray($a);
        }
        $local = Layout::localBufs($f);
        $swap = $c->endian !== 0 ? Layout::byteWidth($f) : 0;
        $bufs = self::take($c, $raw, $bodyAt, $st, $local, $swap);
        if (Layout::variadic($f->type)) {
            if ($st['vi'] >= count($st['var'])) {
                throw new ArrowException(Kind::SCHEMA, $st['vi'], 'variadic');
            }
            $extra = $st['var'][$st['vi']];
            $st['vi']++;
            if ($extra < 0) {
                throw new ArrowException(Kind::SCHEMA, $extra);
            }
            $bufs = array_merge($bufs, self::take($c, $raw, $bodyAt, $st, $extra, 0));
        }
        $a->bufs = $bufs;
        foreach ($f->children as $fid) {
            $a->children[] = self::readArray($c, $raw, $bodyAt, $fid, false, $st, $depth + 1);
        }
        return $c->addArray($a);
    }

    /**
     * @param array{nodeL:list<int>,nodeN:list<int>,off:list<int>,len:list<int>,var:list<int>,ni:int,bi:int,vi:int,codec:int} $st
     * @return list<int>
     */
    private static function take(Columnar $c, string $raw, int $bodyAt, array &$st, int $n, int $swapW): array
    {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            if ($st['bi'] >= count($st['off'])) {
                throw new ArrowException(Kind::SCHEMA, $st['bi'], 'buffer');
            }
            $off = $st['off'][$st['bi']];
            $ln = $st['len'][$st['bi']];
            $st['bi']++;
            if ($ln === 0) {
                $ids[] = $c->addBuf('');
                continue;
            }
            if ($st['base'] >= 0 && $st['codec'] < 0 && !($swapW > 1 && $i === 1)) {
                Bin::checkBuffer($ln);
                if ($off < 0 || $off + $ln > $st['bodyLen']) {
                    throw new ArrowException(Kind::RANGE, $off);
                }
                $c->bufs[] = ['off' => $st['base'] + $off, 'len' => $ln];
                $ids[] = count($c->bufs) - 1;
                continue;
            }
            Bin::checkBuffer($ln);
            $abs = $bodyAt + $off;
            if ($off < 0 || $abs < 0 || $abs + $ln > strlen($raw)) {
                throw new ArrowException(Kind::RANGE, $off);
            }
            $chunk = substr($raw, $abs, $ln);
            if ($st['codec'] >= 0) {
                if ($ln < 8) {
                    throw new ArrowException(Kind::COMPRESSION, $off);
                }
                $un = Bin::i64($chunk, 0);
                $rest = substr($chunk, 8);
                if ($un >= 0) {
                    $chunk = Frame::decompress($st['codec'], $rest);
                    if (strlen($chunk) !== $un) {
                        throw new ArrowException(Kind::COMPRESSION, $off);
                    }
                } else {
                    $chunk = $rest;
                }
            }
            if ($swapW > 1 && $i === 1 && $chunk !== '') {
                $chunk = Bin::swapWidth($chunk, $swapW);
            }
            $ids[] = $c->addBuf($chunk);
        }
        return $ids;
    }

    private static function latestDict(Columnar $c, int $id): int
    {
        for ($i = count($c->dicts) - 1; $i >= 0; $i--) {
            if ($c->dicts[$i]->id === $id && !$c->dicts[$i]->isDelta) {
                return $c->dicts[$i]->array;
            }
        }
        for ($i = count($c->dicts) - 1; $i >= 0; $i--) {
            if ($c->dicts[$i]->id === $id) {
                return $c->dicts[$i]->array;
            }
        }
        return -1;
    }

    /** @param list<array{off:int,meta:int,body:int,kind:int}> $blocks */
    private static function footerBytes(Columnar $c, array $blocks, int $version): string
    {
        $b = new FBBuilder();
        $schema = SchemaFb::schema($b, $c);
        $dicts = self::blockVec($b, $blocks, 2);
        $batches = self::blockVec($b, $blocks, 3);
        $b->startTable(5);
        $b->addU16(0, $version, 0);
        $b->addOffset(1, $schema);
        $b->addOffset(2, $dicts);
        $b->addOffset(3, $batches);
        $raw = $b->finish($b->endTable());
        $mis = strlen($raw) % 8;
        if ($mis !== 0) {
            $raw .= str_repeat("\0", 8 - $mis);
        }
        return $raw;
    }

    /** @param list<array{off:int,meta:int,body:int,kind:int}> $blocks */
    private static function blockVec(FBBuilder $b, array $blocks, int $want): int
    {
        $raw = '';
        foreach ($blocks as $block) {
            if ($block['kind'] !== $want) {
                continue;
            }
            $raw .= pack('q', $block['off']);
            $raw .= pack('V', $block['meta']);
            $raw .= "\0\0\0\0";
            $raw .= pack('q', $block['body']);
        }
        return $b->writeStructVec($raw, 24);
    }

    private static function readFooter(string $raw): Footer
    {
        $r = new FBReader($raw);
        $table = $r->root();
        $footer = new Footer();
        $vp = $r->field($table, 0);
        $ver = $vp >= 0 ? $r->u16($vp) : 0;
        if ($ver !== Meta::V4 && $ver !== Meta::V5) {
            throw new ArrowException(Kind::VERSION, $ver);
        }
        $footer->version = $ver;
        $sp = $r->field($table, 1);
        if ($sp >= 0) {
            $tmp = new Columnar();
            SchemaFb::decode($r, $r->follow($sp), $tmp);
        }
        $footer->dictionaries = self::readBlocks($r, $r->field($table, 2));
        $footer->recordBatches = self::readBlocks($r, $r->field($table, 3));
        return $footer;
    }

    /** @return list<Block> */
    private static function readBlocks(FBReader $r, int $pos): array
    {
        if ($pos < 0) {
            return [];
        }
        $n = $r->vecLen($pos);
        $data = $r->vecData($pos);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $at = $data + $i * 24;
            $out[] = new Block($r->i64($at), $r->i32($at + 8), $r->i64($at + 16));
        }
        return $out;
    }
}
