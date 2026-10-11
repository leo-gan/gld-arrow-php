<?php

declare(strict_types=1);

namespace Gld\Arrow\Runtime;

/**
 * Backward FlatBuffers builder for the Arrow metadata tables.
 * Tokens are distances from the end of the staging buffer so they survive growth.
 */
final class FBReader
{
    public readonly int $n;

    public function __construct(public readonly string $data)
    {
        $this->n = strlen($data);
    }

    public function root(): int
    {
        return $this->u32(0);
    }

    public function field(int $table, int $id): int
    {
        $soff = Bin::i32($this->data, $table);
        $vt = $table - $soff;
        if ($vt < 0 || $vt + 2 > $this->n) {
            throw new ArrowException(Kind::SYNTAX, $table);
        }
        $vtsize = $this->u16($vt);
        if ($vtsize < 4 || $vt + $vtsize > $this->n) {
            throw new ArrowException(Kind::SYNTAX, $vt);
        }
        if (4 + $id * 2 + 2 > $vtsize) {
            return -1;
        }
        $rel = $this->u16($vt + 4 + $id * 2);
        if ($rel === 0) {
            return -1;
        }
        $pos = $table + $rel;
        if ($pos < 0 || $pos >= $this->n) {
            throw new ArrowException(Kind::SYNTAX, $pos);
        }
        return $pos;
    }

    public function follow(int $pos): int
    {
        $rel = $this->u32($pos);
        $at = $pos + $rel;
        if ($at < 0 || $at > $this->n) {
            throw new ArrowException(Kind::SYNTAX, $pos);
        }
        return $at;
    }

    public function vecLen(int $pos): int
    {
        return $this->u32($this->follow($pos));
    }

    public function vecData(int $pos): int
    {
        return $this->follow($pos) + 4;
    }

    public function stringAt(int $pos): string
    {
        $at = $this->follow($pos);
        $n = $this->u32($at);
        Bin::checkBuffer($n);
        $this->need($at + 4, $n);
        $s = substr($this->data, $at + 4, $n);
        Bin::utf8($s, $at);
        return $s;
    }

    public function u8(int $pos): int
    {
        if ($pos < 0 || $pos >= $this->n) {
            throw new ArrowException(Kind::EOF, $pos);
        }
        return ord($this->data[$pos]);
    }

    public function u16(int $pos): int
    {
        $this->need($pos, 2);
        return unpack('v', $this->data, $pos)[1];
    }

    public function i32(int $pos): int
    {
        $u = $this->u32($pos);
        return $u >= 0x80000000 ? $u - 0x100000000 : $u;
    }

    public function u32(int $pos): int
    {
        $this->need($pos, 4);
        return unpack('V', $this->data, $pos)[1];
    }

    public function i64(int $pos): int
    {
        $this->need($pos, 8);
        return unpack('q', $this->data, $pos)[1];
    }

    private function need(int $pos, int $n): void
    {
        if ($pos < 0 || $pos + $n > $this->n) {
            throw new ArrowException(Kind::EOF, $pos);
        }
    }
}
