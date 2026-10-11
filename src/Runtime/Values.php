<?php

declare(strict_types=1);

namespace Gld\Arrow\Runtime;

final class Values
{
    /** @return list<mixed> */
    public static function column(Columnar $c, int $arrayId): array
    {
        return self::read($c, $c->arrays[$arrayId], false);
    }

    /** @return array<string,mixed> */
    public static function row(Columnar $c, int $batch, int $index): array
    {
        $out = [];
        foreach ($c->batches[$batch]->cols as $aid) {
            $a = $c->arrays[$aid];
            $name = $c->fields[$a->field]->name;
            $out[$name] = self::column($c, $aid)[$index];
        }
        return $out;
    }

    /** @return list<mixed> */
    private static function read(Columnar $c, Arr $a, bool $asValues): array
    {
        $f = $c->fields[$a->field];
        if ($f->dictId >= 0 && !$asValues) {
            return self::dictionary($c, $a, $f);
        }
        $n = $a->length;
        $valid = $a->bufs !== [] ? $c->buf($a->bufs[0]) : '';
        if ($f->type === Type::T_NULL || $f->type === Type::T_STRUCT || $f->type === Type::T_RUN_END || $f->type === Type::T_UNION) {
            $valid = match ($f->type) {
                Type::T_UNION, Type::T_RUN_END, Type::T_NULL => '',
                default => ($a->bufs !== [] ? $c->buf($a->bufs[0]) : ''),
            };
        }
        return match ($f->type) {
            Type::T_NULL => array_fill(0, $n, null),
            Type::T_BOOL => self::bools($c, $a, $valid),
            Type::T_INT, Type::T_DATE, Type::T_TIME, Type::T_TIMESTAMP, Type::T_DURATION => self::fixedInts($c, $a, $f, $valid),
            Type::T_FLOAT => self::floats($c, $a, $f, $valid),
            Type::T_DECIMAL, Type::T_FIXED_BINARY, Type::T_INTERVAL => self::fixedBytes($c, $a, $f, $valid),
            Type::T_BINARY, Type::T_UTF8 => self::bytes($c, $a, $valid, 4),
            Type::T_LARGE_BINARY, Type::T_LARGE_UTF8 => self::bytes($c, $a, $valid, 8),
            Type::T_BINARY_VIEW, Type::T_UTF8_VIEW => self::views($c, $a, $valid),
            Type::T_LIST, Type::T_MAP => self::lists($c, $a, $f, $valid, 4, false),
            Type::T_LARGE_LIST => self::lists($c, $a, $f, $valid, 8, false),
            Type::T_LIST_VIEW => self::lists($c, $a, $f, $valid, 4, true),
            Type::T_LARGE_LIST_VIEW => self::lists($c, $a, $f, $valid, 8, true),
            Type::T_FIXED_LIST => self::fixedLists($c, $a, $f, $valid),
            Type::T_STRUCT => self::structs($c, $a, $f, $a->bufs !== [] ? $c->buf($a->bufs[0]) : ''),
            Type::T_UNION => self::unions($c, $a, $f),
            Type::T_RUN_END => self::runs($c, $a, $f),
            default => throw new ArrowException(Kind::TYPE, $f->type),
        };
    }

    /** @return list<mixed> */
    private static function dictionary(Columnar $c, Arr $a, Field $f): array
    {
        if ($a->dict < 0) {
            throw new ArrowException(Kind::SCHEMA, $f->dictId, 'dictionary values');
        }
        $values = self::read($c, $c->arrays[$a->dict], true);
        $valid = $c->buf($a->bufs[0]);
        $idx = $c->buf($a->bufs[1]);
        $width = intdiv($f->dictIndexWidth, 8);
        $out = [];
        for ($i = 0; $i < $a->length; $i++) {
            if (!Layout::validAt($valid, $i, $a->length)) {
                $out[] = null;
                continue;
            }
            $id = self::intAt($idx, $i, $width, $f->dictIndexSigned);
            if ($id < 0 || !array_key_exists($id, $values)) {
                throw new ArrowException(Kind::RANGE, $id, 'dictionary index');
            }
            $out[] = $values[$id];
        }
        return $out;
    }

    /** @return list<bool|null> */
    private static function bools(Columnar $c, Arr $a, string $valid): array
    {
        $bits = $c->buf($a->bufs[1]);
        $out = [];
        for ($i = 0; $i < $a->length; $i++) {
            if (!Layout::validAt($valid, $i, $a->length)) {
                $out[] = null;
                continue;
            }
            $out[] = $bits !== '' && ((ord($bits[intdiv($i, 8)] ?? "\0") >> ($i & 7)) & 1) === 1;
        }
        return $out;
    }

    /** @return list<int|null> */
    private static function fixedInts(Columnar $c, Arr $a, Field $f, string $valid): array
    {
        $width = Layout::byteWidth($f);
        $raw = $c->buf($a->bufs[1]);
        $signed = $f->type === Type::T_INT ? $f->signed : true;
        $out = [];
        for ($i = 0; $i < $a->length; $i++) {
            if (!Layout::validAt($valid, $i, $a->length)) {
                $out[] = null;
                continue;
            }
            $out[] = self::intAt($raw, $i, $width, $signed);
        }
        return $out;
    }

    /** @return list<float|int|null> */
    private static function floats(Columnar $c, Arr $a, Field $f, string $valid): array
    {
        $width = Layout::byteWidth($f);
        $raw = $c->buf($a->bufs[1]);
        $out = [];
        for ($i = 0; $i < $a->length; $i++) {
            if (!Layout::validAt($valid, $i, $a->length)) {
                $out[] = null;
                continue;
            }
            $o = $i * $width;
            if ($width === 2) {
                $out[] = Bin::u16($raw, $o);
            } elseif ($width === 4) {
                $out[] = unpack('g', substr($raw, $o, 4))[1];
            } else {
                $out[] = unpack('e', substr($raw, $o, 8))[1];
            }
        }
        return $out;
    }

    /** @return list<string|array<int,int>|int|null> */
    private static function fixedBytes(Columnar $c, Arr $a, Field $f, string $valid): array
    {
        $width = Layout::byteWidth($f);
        $raw = $c->buf($a->bufs[1]);
        $out = [];
        for ($i = 0; $i < $a->length; $i++) {
            if (!Layout::validAt($valid, $i, $a->length)) {
                $out[] = null;
                continue;
            }
            $slice = substr($raw, $i * $width, $width);
            if ($f->type === Type::T_INTERVAL && $f->unit === 0) {
                $out[] = Bin::i32($slice, 0);
            } elseif ($f->type === Type::T_INTERVAL && $f->unit === 1) {
                $out[] = [Bin::i32($slice, 0), Bin::i32($slice, 4)];
            } elseif ($f->type === Type::T_INTERVAL) {
                $out[] = [Bin::i32($slice, 0), Bin::i32($slice, 4), Bin::i64($slice, 8)];
            } else {
                $out[] = $slice;
            }
        }
        return $out;
    }

    /** @return list<string|null> */
    private static function bytes(Columnar $c, Arr $a, string $valid, int $offWidth): array
    {
        $offs = $c->buf($a->bufs[1]);
        $data = $c->buf($a->bufs[2]);
        $out = [];
        for ($i = 0; $i < $a->length; $i++) {
            if (!Layout::validAt($valid, $i, $a->length)) {
                $out[] = null;
                continue;
            }
            $start = self::intAt($offs, $i, $offWidth, true);
            $end = self::intAt($offs, $i + 1, $offWidth, true);
            if ($start < 0 || $end < $start || $end > strlen($data)) {
                throw new ArrowException(Kind::RANGE, $start);
            }
            $out[] = substr($data, $start, $end - $start);
        }
        return $out;
    }

    /** @return list<string|null> */
    private static function views(Columnar $c, Arr $a, string $valid): array
    {
        $views = $c->buf($a->bufs[1]);
        $data = [];
        for ($i = 2; $i < count($a->bufs); $i++) {
            $data[] = $c->buf($a->bufs[$i]);
        }
        $out = [];
        for ($i = 0; $i < $a->length; $i++) {
            if (!Layout::validAt($valid, $i, $a->length)) {
                $out[] = null;
                continue;
            }
            $base = $i * 16;
            Bin::need($views, $base, 16);
            $len = Bin::u32($views, $base);
            if ($len <= 12) {
                $out[] = substr($views, $base + 4, $len);
                continue;
            }
            $bufIndex = Bin::u32($views, $base + 8);
            $off = Bin::u32($views, $base + 12);
            if (!isset($data[$bufIndex]) || $off < 0 || $off + $len > strlen($data[$bufIndex])) {
                throw new ArrowException(Kind::RANGE, $off);
            }
            $out[] = substr($data[$bufIndex], $off, $len);
        }
        return $out;
    }

    /** @return list<mixed> */
    private static function lists(Columnar $c, Arr $a, Field $f, string $valid, int $offWidth, bool $view): array
    {
        $offs = $c->buf($a->bufs[1]);
        $sizes = $view ? $c->buf($a->bufs[2]) : '';
        $child = self::read($c, $c->arrays[$a->children[0]], false);
        $out = [];
        for ($i = 0; $i < $a->length; $i++) {
            if (!Layout::validAt($valid, $i, $a->length)) {
                $out[] = null;
                continue;
            }
            $start = self::intAt($offs, $i, $offWidth, true);
            $len = $view ? self::intAt($sizes, $i, $offWidth, true) : self::intAt($offs, $i + 1, $offWidth, true) - $start;
            if ($start < 0 || $len < 0 || $start + $len > count($child)) {
                throw new ArrowException(Kind::RANGE, $start);
            }
            $slice = array_slice($child, $start, $len);
            if ($f->type === Type::T_MAP) {
                $pairs = [];
                foreach ($slice as $entry) {
                    if (!is_array($entry)) {
                        $pairs[] = $entry;
                        continue;
                    }
                    $pairs[] = [$entry['key'] ?? null, $entry['value'] ?? null];
                }
                $out[] = $pairs;
            } else {
                $out[] = $slice;
            }
        }
        return $out;
    }

    /** @return list<mixed> */
    private static function fixedLists(Columnar $c, Arr $a, Field $f, string $valid): array
    {
        $child = self::read($c, $c->arrays[$a->children[0]], false);
        $size = $f->fixedSize;
        $out = [];
        for ($i = 0; $i < $a->length; $i++) {
            if (!Layout::validAt($valid, $i, $a->length)) {
                $out[] = null;
                continue;
            }
            $out[] = array_slice($child, $i * $size, $size);
        }
        return $out;
    }

    /** @return list<array<string,mixed>|null> */
    private static function structs(Columnar $c, Arr $a, Field $f, string $valid): array
    {
        $kids = [];
        foreach ($f->children as $ch => $fid) {
            $name = $c->fields[$fid]->name;
            if ($name === '') {
                $name = '#' . $ch;
            }
            $kids[$name] = self::read($c, $c->arrays[$a->children[$ch]], false);
        }
        $out = [];
        for ($i = 0; $i < $a->length; $i++) {
            if (!Layout::validAt($valid, $i, $a->length)) {
                $out[] = null;
                continue;
            }
            $row = [];
            foreach ($kids as $name => $col) {
                $row[$name] = $col[$i];
            }
            $out[] = $row;
        }
        return $out;
    }

    /** @return list<array{id:int,value:mixed}|null> */
    private static function unions(Columnar $c, Arr $a, Field $f): array
    {
        $types = $c->buf($a->bufs[0]);
        $offsets = $f->unionMode === 1 ? $c->buf($a->bufs[1]) : '';
        $children = [];
        foreach ($a->children as $cid) {
            $children[] = self::read($c, $c->arrays[$cid], false);
        }
        $out = [];
        for ($i = 0; $i < $a->length; $i++) {
            $id = $i < strlen($types) ? ord($types[$i]) : 0;
            $slot = self::childSlot($f, $id);
            if ($slot < 0 || !isset($children[$slot])) {
                throw new ArrowException(Kind::TYPE, $id);
            }
            $at = $f->unionMode === 1 ? Bin::i32($offsets, $i * 4) : $i;
            $out[] = ['id' => $id, 'value' => $children[$slot][$at]];
        }
        return $out;
    }

    private static function childSlot(Field $f, int $id): int
    {
        if ($f->typeIds === []) {
            return $id;
        }
        $slot = array_search($id, $f->typeIds, true);
        return $slot === false ? -1 : (int) $slot;
    }

    /** @return list<mixed> */
    private static function runs(Columnar $c, Arr $a, Field $f): array
    {
        $ends = self::read($c, $c->arrays[$a->children[0]], false);
        $vals = self::read($c, $c->arrays[$a->children[1]], false);
        $out = [];
        $prev = 0;
        foreach ($ends as $ri => $end) {
            $end = (int) $end;
            $value = $vals[$ri];
            for ($i = $prev; $i < $end; $i++) {
                $out[] = $value;
            }
            $prev = $end;
        }
        if (count($out) !== $a->length) {
            throw new ArrowException(Kind::SCHEMA, count($out), 'run-end length');
        }
        return $out;
    }

    private static function intAt(string $raw, int $index, int $width, bool $signed): int
    {
        $o = $index * $width;
        Bin::need($raw, $o, $width);
        if ($width === 1) {
            $u = ord($raw[$o]);
            return $signed && $u >= 128 ? $u - 256 : $u;
        }
        if ($width === 2) {
            $u = Bin::u16($raw, $o);
            return $signed && $u >= 32768 ? $u - 65536 : $u;
        }
        if ($width === 4) {
            return $signed ? Bin::i32($raw, $o) : Bin::u32($raw, $o);
        }
        if ($width === 8) {
            return Bin::i64($raw, $o);
        }
        throw new ArrowException(Kind::TYPE, $width);
    }
}
