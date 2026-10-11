<?php

declare(strict_types=1);

namespace Gld\Arrow\Runtime;

final class Bin
{
    public static function u8(string $s, int $i): int
    {
        if ($i < 0 || $i >= strlen($s)) {
            throw new ArrowException(Kind::EOF, $i);
        }
        return ord($s[$i]);
    }

    public static function u16(string $s, int $i): int
    {
        self::need($s, $i, 2);
        return unpack('v', substr($s, $i, 2))[1];
    }

    public static function u32(string $s, int $i): int
    {
        self::need($s, $i, 4);
        return unpack('V', substr($s, $i, 4))[1];
    }

    public static function i32(string $s, int $i): int
    {
        $u = self::u32($s, $i);
        if ($u >= 0x80000000) {
            return $u - 0x100000000;
        }
        return $u;
    }

    public static function i64(string $s, int $i): int
    {
        self::need($s, $i, 8);
        return unpack('q', substr($s, $i, 8))[1];
    }

    public static function need(string $s, int $i, int $n): void
    {
        if ($i < 0 || $n < 0 || $i + $n > strlen($s)) {
            throw new ArrowException(Kind::EOF, $i);
        }
    }

    public static function checkBuffer(int $n): void
    {
        if ($n < 0 || $n > Limits::BUFFER) {
            throw new ArrowException(Kind::RANGE, $n, 'buffer');
        }
    }

    public static function putU32(string &$out, int $v): void
    {
        $out .= pack('V', $v);
    }

    public static function putI64(string &$out, int $v): void
    {
        $out .= pack('q', $v);
    }

    public static function u32mul(int $a, int $b): int
    {
        $a &= 0xFFFFFFFF;
        $b &= 0xFFFFFFFF;
        $al = $a & 0xFFFF;
        $ah = ($a >> 16) & 0xFFFF;
        $bl = $b & 0xFFFF;
        $bh = ($b >> 16) & 0xFFFF;
        return ($al * $bl + ((($ah * $bl + $al * $bh) & 0xFFFFFFFF) << 16)) & 0xFFFFFFFF;
    }

    public static function rotl32(int $x, int $r): int
    {
        $x &= 0xFFFFFFFF;
        $r &= 31;
        return (($x << $r) | ($x >> (32 - $r))) & 0xFFFFFFFF;
    }

    /** @param array{0:int,1:int} $a @param array{0:int,1:int} $b @return array{0:int,1:int} */
    public static function add64(array $a, array $b): array
    {
        $low = ($a[0] & 0xFFFFFFFF) + ($b[0] & 0xFFFFFFFF);
        $high = ($a[1] & 0xFFFFFFFF) + ($b[1] & 0xFFFFFFFF) + ($low >> 32);
        return [$low & 0xFFFFFFFF, $high & 0xFFFFFFFF];
    }

    /** @param array{0:int,1:int} $a @param array{0:int,1:int} $b @return array{0:int,1:int} */
    public static function xor64(array $a, array $b): array
    {
        return [($a[0] ^ $b[0]) & 0xFFFFFFFF, ($a[1] ^ $b[1]) & 0xFFFFFFFF];
    }

    /** @param array{0:int,1:int} $x @return array{0:int,1:int} */
    public static function rotl64(array $x, int $r): array
    {
        $r &= 63;
        if ($r === 0) {
            return [$x[0] & 0xFFFFFFFF, $x[1] & 0xFFFFFFFF];
        }
        $lo = $x[0] & 0xFFFFFFFF;
        $hi = $x[1] & 0xFFFFFFFF;
        if ($r === 32) {
            return [$hi, $lo];
        }
        if ($r < 32) {
            return [
                (($lo << $r) | ($hi >> (32 - $r))) & 0xFFFFFFFF,
                (($hi << $r) | ($lo >> (32 - $r))) & 0xFFFFFFFF,
            ];
        }
        $r -= 32;
        return [
            (($hi << $r) | ($lo >> (32 - $r))) & 0xFFFFFFFF,
            (($lo << $r) | ($hi >> (32 - $r))) & 0xFFFFFFFF,
        ];
    }

    /** @param array{0:int,1:int} $x @return array{0:int,1:int} */
    public static function shr64(array $x, int $r): array
    {
        if ($r <= 0) {
            return [$x[0] & 0xFFFFFFFF, $x[1] & 0xFFFFFFFF];
        }
        if ($r >= 64) {
            return [0, 0];
        }
        $lo = $x[0] & 0xFFFFFFFF;
        $hi = $x[1] & 0xFFFFFFFF;
        if ($r === 32) {
            return [$hi, 0];
        }
        if ($r < 32) {
            return [(($lo >> $r) | ($hi << (32 - $r))) & 0xFFFFFFFF, $hi >> $r];
        }
        return [$hi >> ($r - 32), 0];
    }

    /** @param array{0:int,1:int} $a @param array{0:int,1:int} $b @return array{0:int,1:int} */
    public static function mul64(array $a, array $b): array
    {
        $al = [
            $a[0] & 0xFFFF,
            ($a[0] >> 16) & 0xFFFF,
            $a[1] & 0xFFFF,
            ($a[1] >> 16) & 0xFFFF,
        ];
        $bl = [
            $b[0] & 0xFFFF,
            ($b[0] >> 16) & 0xFFFF,
            $b[1] & 0xFFFF,
            ($b[1] >> 16) & 0xFFFF,
        ];
        $acc = [0, 0, 0, 0];
        for ($i = 0; $i < 4; $i++) {
            for ($j = 0; $j < 4 - $i; $j++) {
                $acc[$i + $j] += $al[$i] * $bl[$j];
            }
        }
        $carry = 0;
        for ($k = 0; $k < 4; $k++) {
            $acc[$k] += $carry;
            $carry = $acc[$k] >> 16;
            $acc[$k] &= 0xFFFF;
        }
        return [
            $acc[0] | ($acc[1] << 16),
            $acc[2] | ($acc[3] << 16),
        ];
    }

    /** @return array{0:int,1:int} */
    public static function u64(int $v): array
    {
        if ($v >= 0) {
            return [$v & 0xFFFFFFFF, ($v >> 32) & 0xFFFFFFFF];
        }
        $raw = pack('q', $v);
        return [unpack('V', substr($raw, 0, 4))[1], unpack('V', substr($raw, 4, 4))[1]];
    }

    /** @param array{0:int,1:int} $v */
    public static function pack64(array $v): string
    {
        return pack('V', $v[0] & 0xFFFFFFFF) . pack('V', $v[1] & 0xFFFFFFFF);
    }

    public static function utf8(string $s, int $at = 0): void
    {
        if ($s === '' || preg_match('//u', $s) === 1) {
            return;
        }
        throw new ArrowException(Kind::UTF8, $at);
    }

    public static function swapWidth(string $raw, int $width): string
    {
        if ($width <= 1 || $raw === '') {
            return $raw;
        }
        $n = strlen($raw);
        $out = $raw;
        for ($i = 0; $i + $width <= $n; $i += $width) {
            for ($a = 0, $b = $width - 1; $a < $b; $a++, $b--) {
                $tmp = $out[$i + $a];
                $out[$i + $a] = $out[$i + $b];
                $out[$i + $b] = $tmp;
            }
        }
        return $out;
    }

    public static function pad8(string $s): string
    {
        $m = strlen($s) % 8;
        if ($m === 0) {
            return $s;
        }
        return $s . str_repeat("\0", 8 - $m);
    }
}
