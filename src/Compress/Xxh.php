<?php

declare(strict_types=1);

namespace Gld\Arrow\Compress;

use Gld\Arrow\Runtime\Bin;

final class Xxh
{
    private const P1 = 0x9E3779B1;
    private const P2 = 0x85EBCA77;
    private const P3 = 0xC2B2AE3D;
    private const P4 = 0x27D4EB2F;
    private const P5 = 0x165667B1;

    public static function xxh32(string $raw): int
    {
        $n = strlen($raw);
        $i = 0;
        $h = self::P5;
        if ($n >= 16) {
            $v1 = (self::P1 + self::P2) & 0xFFFFFFFF;
            $v2 = self::P2;
            $v3 = 0;
            $v4 = (0 - self::P1) & 0xFFFFFFFF;
            while ($i + 16 <= $n) {
                $v1 = self::round32($v1, self::load32($raw, $i));
                $v2 = self::round32($v2, self::load32($raw, $i + 4));
                $v3 = self::round32($v3, self::load32($raw, $i + 8));
                $v4 = self::round32($v4, self::load32($raw, $i + 12));
                $i += 16;
            }
            $h = (Bin::rotl32($v1, 1) + Bin::rotl32($v2, 7) + Bin::rotl32($v3, 12) + Bin::rotl32($v4, 18)) & 0xFFFFFFFF;
        }
        $h = ($h + $n) & 0xFFFFFFFF;
        while ($i + 4 <= $n) {
            $h = ($h + Bin::u32mul(self::load32($raw, $i), self::P3)) & 0xFFFFFFFF;
            $h = Bin::u32mul(Bin::rotl32($h, 17), self::P4);
            $i += 4;
        }
        while ($i < $n) {
            $h = ($h + Bin::u32mul(ord($raw[$i]), self::P5)) & 0xFFFFFFFF;
            $h = Bin::u32mul(Bin::rotl32($h, 11), self::P1);
            $i++;
        }
        $h = ($h ^ ($h >> 15)) & 0xFFFFFFFF;
        $h = Bin::u32mul($h, self::P2);
        $h = ($h ^ ($h >> 13)) & 0xFFFFFFFF;
        $h = Bin::u32mul($h, self::P3);
        $h = ($h ^ ($h >> 16)) & 0xFFFFFFFF;
        return $h;
    }

    /** @return array{0:int,1:int} */
    public static function xxh64(string $raw): array
    {
        $p1 = [0x85EBCA87, 0x9E3779B1];
        $p2 = [0x27D4EB4F, 0xC2B2AE3D];
        $p3 = [0x9E3779F9, 0x165667B1];
        $p4 = [0xC2B2AE63, 0x85EBCA77];
        $p5 = [0x165667C5, 0x27D4EB2F];
        $n = strlen($raw);
        $i = 0;
        $h = $p5;
        if ($n >= 32) {
            $v1 = Bin::add64($p1, $p2);
            $v2 = $p2;
            $v3 = [0, 0];
            $v4 = self::neg64($p1);
            while ($i + 32 <= $n) {
                $v1 = self::round64($v1, self::load64($raw, $i), $p1, $p2);
                $v2 = self::round64($v2, self::load64($raw, $i + 8), $p1, $p2);
                $v3 = self::round64($v3, self::load64($raw, $i + 16), $p1, $p2);
                $v4 = self::round64($v4, self::load64($raw, $i + 24), $p1, $p2);
                $i += 32;
            }
            $h = Bin::add64(
                Bin::add64(Bin::rotl64($v1, 1), Bin::rotl64($v2, 7)),
                Bin::add64(Bin::rotl64($v3, 12), Bin::rotl64($v4, 18)),
            );
            $h = self::merge64($h, $v1, $p1, $p2, $p4);
            $h = self::merge64($h, $v2, $p1, $p2, $p4);
            $h = self::merge64($h, $v3, $p1, $p2, $p4);
            $h = self::merge64($h, $v4, $p1, $p2, $p4);
        }
        $h = Bin::add64($h, Bin::u64($n));
        while ($i + 8 <= $n) {
            $k1 = self::round64([0, 0], self::load64($raw, $i), $p1, $p2);
            $h = Bin::xor64($h, $k1);
            $h = Bin::add64(Bin::mul64(Bin::rotl64($h, 27), $p1), $p4);
            $i += 8;
        }
        if ($i + 4 <= $n) {
            $lane = [self::load32($raw, $i), 0];
            $h = Bin::xor64($h, Bin::mul64($lane, $p1));
            $h = Bin::add64(Bin::mul64(Bin::rotl64($h, 23), $p2), $p3);
            $i += 4;
        }
        while ($i < $n) {
            $lane = [ord($raw[$i]), 0];
            $h = Bin::xor64($h, Bin::mul64($lane, $p5));
            $h = Bin::mul64(Bin::rotl64($h, 11), $p1);
            $i++;
        }
        $h = Bin::xor64($h, Bin::shr64($h, 33));
        $h = Bin::mul64($h, $p2);
        $h = Bin::xor64($h, Bin::shr64($h, 29));
        $h = Bin::mul64($h, $p3);
        $h = Bin::xor64($h, Bin::shr64($h, 32));
        return $h;
    }

    public static function xxh64Low32(string $raw): int
    {
        return self::xxh64($raw)[0];
    }

    /** @param array{0:int,1:int} $x @return array{0:int,1:int} */
    private static function neg64(array $x): array
    {
        $low = (~$x[0]) & 0xFFFFFFFF;
        $high = (~$x[1]) & 0xFFFFFFFF;
        $low = ($low + 1) & 0xFFFFFFFF;
        if ($low === 0) {
            $high = ($high + 1) & 0xFFFFFFFF;
        }
        return [$low, $high];
    }

    private static function round32(int $acc, int $lane): int
    {
        $x = ($acc + Bin::u32mul($lane, self::P2)) & 0xFFFFFFFF;
        $x = Bin::rotl32($x, 13);
        return Bin::u32mul($x, self::P1);
    }

    /**
     * @param array{0:int,1:int} $acc
     * @param array{0:int,1:int} $lane
     * @param array{0:int,1:int} $p1
     * @param array{0:int,1:int} $p2
     * @return array{0:int,1:int}
     */
    private static function round64(array $acc, array $lane, array $p1, array $p2): array
    {
        $x = Bin::add64($acc, Bin::mul64($lane, $p2));
        $x = Bin::rotl64($x, 31);
        return Bin::mul64($x, $p1);
    }

    /**
     * @param array{0:int,1:int} $h
     * @param array{0:int,1:int} $v
     * @param array{0:int,1:int} $p1
     * @param array{0:int,1:int} $p2
     * @param array{0:int,1:int} $p4
     * @return array{0:int,1:int}
     */
    private static function merge64(array $h, array $v, array $p1, array $p2, array $p4): array
    {
        $k = self::round64([0, 0], $v, $p1, $p2);
        $h = Bin::xor64($h, $k);
        return Bin::add64(Bin::mul64($h, $p1), $p4);
    }

    private static function load32(string $raw, int $i): int
    {
        return unpack('V', substr($raw, $i, 4))[1];
    }

    /** @return array{0:int,1:int} */
    private static function load64(string $raw, int $i): array
    {
        return [
            unpack('V', substr($raw, $i, 4))[1],
            unpack('V', substr($raw, $i + 4, 4))[1],
        ];
    }
}
