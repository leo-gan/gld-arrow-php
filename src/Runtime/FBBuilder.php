<?php

declare(strict_types=1);

namespace Gld\Arrow\Runtime;

/**
 * Backward FlatBuffers builder for the Arrow metadata tables.
 * Tokens are distances from the end of the staging buffer so they survive growth.
 */
final class FBBuilder
{
    private string $data;
    private int $cursor;
    /** @var list<int> */
    private array $fieldAt = [];
    private int $nfields = 0;
    private int $high = 0;
    private int $alignNeed = 4;

    public function __construct()
    {
        $this->data = str_repeat("\0", 4096);
        $this->cursor = strlen($this->data);
        $this->high = $this->cursor;
    }

    private function tok(int $abs): int
    {
        return strlen($this->data) - $abs;
    }

    private function abs(int $tok): int
    {
        return strlen($this->data) - $tok;
    }

    private function grow(int $need): void
    {
        if ($this->cursor >= $need) {
            return;
        }
        $cap = strlen($this->data);
        $biggerN = max($cap * 2, $cap + $need + 64);
        while ($biggerN - ($cap - $this->cursor) < $need + 64) {
            $biggerN *= 2;
        }
        $bigger = str_repeat("\0", $biggerN);
        $used = $cap - $this->cursor;
        $shift = $biggerN - $cap;
        if ($used > 0) {
            $bigger = substr_replace($bigger, substr($this->data, $this->cursor, $used), $this->cursor + $shift, $used);
        }
        $this->cursor += $shift;
        $this->high += $shift;
        foreach ($this->fieldAt as $i => $at) {
            if ($at >= 0) {
                $this->fieldAt[$i] = $at + $shift;
            }
        }
        $this->data = $bigger;
    }

    private function place(int $size, int $align): void
    {
        $this->grow($size + $align);
        $start = $this->cursor - $size;
        $mis = $start % $align;
        if ($mis !== 0) {
            $this->cursor -= $mis;
        }
        $this->cursor -= $size;
    }

    private static function byte(int $v): string
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            for ($i = 0; $i < 256; $i++) {
                $map[$i] = chr($i);
            }
        }
        return $map[$v & 255];
    }

    private function setU16(int $at, int $v): void
    {
        $this->data[$at] = self::byte($v);
        $this->data[$at + 1] = self::byte($v >> 8);
    }

    private function setU32(int $at, int $v): void
    {
        $packed = pack('V', $v);
        $this->data[$at] = $packed[0];
        $this->data[$at + 1] = $packed[1];
        $this->data[$at + 2] = $packed[2];
        $this->data[$at + 3] = $packed[3];
    }

    private function setU64(int $at, int $v): void
    {
        $raw = pack('q', $v);
        $this->data[$at] = $raw[0];
        $this->data[$at + 1] = $raw[1];
        $this->data[$at + 2] = $raw[2];
        $this->data[$at + 3] = $raw[3];
        $this->data[$at + 4] = $raw[4];
        $this->data[$at + 5] = $raw[5];
        $this->data[$at + 6] = $raw[6];
        $this->data[$at + 7] = $raw[7];
    }

    public function startTable(int $nfields): void
    {
        for ($i = 0; $i < $nfields; $i++) {
            $this->fieldAt[$i] = -1;
        }
        $this->nfields = $nfields;
        $this->high = $this->cursor;
        $this->alignNeed = 4;
    }

    private function note(int $id, int $align): void
    {
        $this->fieldAt[$id] = $this->cursor;
        if ($align > $this->alignNeed) {
            $this->alignNeed = $align;
        }
    }

    public function addBool(int $id, bool $v, bool $default): void
    {
        if ($v === $default) {
            return;
        }
        $this->place(1, 1);
        $this->data[$this->cursor] = $v ? self::byte(1) : self::byte(0);
        $this->note($id, 1);
    }

    public function addU8(int $id, int $v, int $default): void
    {
        if ($v === $default) {
            return;
        }
        $this->place(1, 1);
        $this->data[$this->cursor] = self::byte($v);
        $this->note($id, 1);
    }

    public function addU16(int $id, int $v, int $default): void
    {
        if ($v === $default) {
            return;
        }
        $this->place(2, 2);
        $this->setU16($this->cursor, $v);
        $this->note($id, 2);
    }

    public function addI32(int $id, int $v, int $default): void
    {
        if ($v === $default) {
            return;
        }
        $this->place(4, 4);
        $this->setU32($this->cursor, $v);
        $this->note($id, 4);
    }

    public function addI64(int $id, int $v, int $default): void
    {
        if ($v === $default) {
            return;
        }
        $this->place(8, 8);
        $this->setU64($this->cursor, $v);
        $this->note($id, 8);
    }

    public function addI64Pair(int $id, int $first, int $second): void
    {
        $this->place(16, 8);
        $this->setU64($this->cursor, $first);
        $this->setU64($this->cursor + 8, $second);
        $this->note($id, 8);
    }

    public function addOffset(int $id, int $tok): void
    {
        if ($tok < 0) {
            return;
        }
        $this->place(4, 4);
        $target = $this->abs($tok);
        $this->setU32($this->cursor, $target - $this->cursor);
        $this->note($id, 4);
    }

    public function endTable(): int
    {
        $this->place(4, $this->alignNeed);
        $table = $this->cursor;
        $this->setU32($table, 0);
        $tableTok = $this->tok($table);
        $obj = $this->high - $table;
        $vtsize = 4 + 2 * $this->nfields;
        $this->place($vtsize, 2);
        $table = $this->abs($tableTok);
        $this->setU16($this->cursor, $vtsize);
        $this->setU16($this->cursor + 2, $obj);
        for ($i = 0; $i < $this->nfields; $i++) {
            $rel = 0;
            $at = $this->fieldAt[$i] ?? -1;
            if ($at >= 0) {
                $rel = $at - $table;
            }
            $this->setU16($this->cursor + 4 + $i * 2, $rel);
        }
        $soff = $table - $this->cursor;
        $this->setU32($table, $soff);
        return $tableTok;
    }

    public function writeString(string $text): int
    {
        return $this->writeBytes($text, true);
    }

    public function writeBytes(string $raw, bool $nullTerm): int
    {
        $n = strlen($raw);
        $extra = $nullTerm ? 1 : 0;
        $total = $n + $extra;
        $this->grow($total + 8);
        $mis = ($this->cursor - $total) % 4;
        if ($mis !== 0) {
            $this->cursor -= $mis;
        }
        $start = $this->cursor - $total;
        for ($i = 0; $i < $n; $i++) {
            $this->data[$start + $i] = $raw[$i];
        }
        if ($nullTerm) {
            $this->data[$start + $n] = "\0";
        }
        $this->cursor = $start;
        $this->place(4, 4);
        $this->setU32($this->cursor, $n);
        return $this->tok($this->cursor);
    }

    /** @param list<int> $vals */
    public function writeU32Vec(array $vals): int
    {
        for ($i = count($vals) - 1; $i >= 0; $i--) {
            $this->place(4, 4);
            $this->setU32($this->cursor, $vals[$i]);
        }
        $this->place(4, 4);
        $this->setU32($this->cursor, count($vals));
        return $this->tok($this->cursor);
    }

    /** @param list<int> $vals */
    public function writeU64Vec(array $vals): int
    {
        for ($i = count($vals) - 1; $i >= 0; $i--) {
            $this->place(8, 8);
            $this->setU64($this->cursor, $vals[$i]);
        }
        $this->place(4, 4);
        $this->setU32($this->cursor, count($vals));
        return $this->tok($this->cursor);
    }

    /** @param list<int> $targets */
    public function writeOffsetVec(array $targets): int
    {
        for ($i = count($targets) - 1; $i >= 0; $i--) {
            $this->place(4, 4);
            $target = $this->abs($targets[$i]);
            $this->setU32($this->cursor, $target - $this->cursor);
        }
        $this->place(4, 4);
        $this->setU32($this->cursor, count($targets));
        return $this->tok($this->cursor);
    }

    /** @param list<int> $first @param list<int> $second */
    public function writeI64Pairs(array $first, array $second): int
    {
        $n = count($first);
        $nbytes = $n * 16;
        $this->grow($nbytes + 16);
        $mis = ($this->cursor - $nbytes) % 8;
        if ($mis !== 0) {
            $this->cursor -= $mis;
        }
        for ($i = $n - 1; $i >= 0; $i--) {
            $this->cursor -= 16;
            $this->setU64($this->cursor, $first[$i]);
            $this->setU64($this->cursor + 8, $second[$i]);
        }
        $this->place(4, 4);
        $this->setU32($this->cursor, $n);
        return $this->tok($this->cursor);
    }

    public function writeStructVec(string $raw, int $elem): int
    {
        $n = $elem > 0 ? intdiv(strlen($raw), $elem) : 0;
        $align = $elem >= 8 ? 8 : 4;
        $len = strlen($raw);
        $this->grow($len + $align + 8);
        $mis = ($this->cursor - $len) % $align;
        if ($mis !== 0) {
            $this->cursor -= $mis;
        }
        $this->cursor -= $len;
        if ($len > 0) {
            $this->data = substr_replace($this->data, $raw, $this->cursor, $len);
        }
        $this->place(4, 4);
        $this->setU32($this->cursor, $n);
        return $this->tok($this->cursor);
    }

    public function finish(int $tok): string
    {
        $this->place(4, 8);
        $root = $this->abs($tok);
        $this->setU32($this->cursor, $root - $this->cursor);
        return substr($this->data, $this->cursor);
    }
}

