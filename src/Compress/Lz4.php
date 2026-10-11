<?php

declare(strict_types=1);

namespace Gld\Arrow\Compress;

use Gld\Arrow\Runtime\ArrowException;
use Gld\Arrow\Runtime\Bin;
use Gld\Arrow\Runtime\Kind;
use Gld\Arrow\Runtime\Limits;

final class Lz4
{
    private const MAGIC = 0x184D2204;
    private const BLOCK_MAX = 65536;
    private const HASH_LOG = 12;

    public static function compress(string $raw): string
    {
        if (strlen($raw) > Limits::CODEC) {
            throw new ArrowException(Kind::COMPRESSION, strlen($raw));
        }
        $out = pack('V', self::MAGIC);
        $flg = 0x60;
        $bd = 0x40;
        $hdr = chr($flg) . chr($bd);
        $sum = Xxh::xxh32($hdr);
        $out .= $hdr . chr(($sum >> 8) & 0xFF);
        if ($raw === '') {
            self::writeStored($out, $raw);
        } else {
            $off = 0;
            $n = strlen($raw);
            while ($off < $n) {
                $take = min(self::BLOCK_MAX, $n - $off);
                self::writeBlock($out, substr($raw, $off, $take));
                $off += $take;
            }
        }
        $out .= "\0\0\0\0";
        return $out;
    }

    public static function decompress(string $raw): string
    {
        if (strlen($raw) < 7) {
            throw new ArrowException(Kind::COMPRESSION, 0);
        }
        $magic = unpack('V', substr($raw, 0, 4))[1];
        if ($magic !== self::MAGIC) {
            throw new ArrowException(Kind::COMPRESSION, 0);
        }
        $flg = ord($raw[4]);
        $bd = ord($raw[5]);
        if (($flg >> 6) !== 1 || ($flg & 0x02) !== 0) {
            throw new ArrowException(Kind::COMPRESSION, 4);
        }
        if (($bd & 0x8F) !== 0) {
            throw new ArrowException(Kind::COMPRESSION, 5);
        }
        $maxCode = ($bd >> 4) & 7;
        $blockMax = self::blockMax($maxCode);
        $independent = ($flg >> 5) & 1;
        $blockCheck = ($flg >> 4) & 1;
        $sizeFlag = ($flg >> 3) & 1;
        $contentCheck = ($flg >> 2) & 1;
        $dictFlag = $flg & 1;
        $hdr = $raw[4] . $raw[5];
        $pos = 6;
        $contentSize = -1;
        if ($sizeFlag !== 0) {
            if ($pos + 8 > strlen($raw)) {
                throw new ArrowException(Kind::COMPRESSION, $pos);
            }
            $declared = unpack('q', substr($raw, $pos, 8))[1];
            if ($declared < 0 || $declared > Limits::CODEC) {
                throw new ArrowException(Kind::COMPRESSION, $pos);
            }
            $contentSize = $declared;
            $hdr .= substr($raw, $pos, 8);
            $pos += 8;
        }
        if ($dictFlag !== 0) {
            if ($pos + 4 > strlen($raw)) {
                throw new ArrowException(Kind::COMPRESSION, $pos);
            }
            $hdr .= substr($raw, $pos, 4);
            $pos += 4;
        }
        if ($pos >= strlen($raw)) {
            throw new ArrowException(Kind::COMPRESSION, $pos);
        }
        $expect = (Xxh::xxh32($hdr) >> 8) & 0xFF;
        if (ord($raw[$pos]) !== $expect) {
            throw new ArrowException(Kind::COMPRESSION, $pos);
        }
        $pos++;
        if ($dictFlag !== 0) {
            throw new ArrowException(Kind::COMPRESSION, 4, 'dictionary');
        }
        $out = '';
        $n = strlen($raw);
        while (true) {
            if ($pos + 4 > $n) {
                throw new ArrowException(Kind::COMPRESSION, $pos);
            }
            $sizeWord = unpack('V', substr($raw, $pos, 4))[1];
            $pos += 4;
            if ($sizeWord === 0) {
                break;
            }
            $stored = ($sizeWord & 0x80000000) !== 0;
            $size = $sizeWord & 0x7FFFFFFF;
            if ($size > $blockMax || $pos + $size > $n) {
                throw new ArrowException(Kind::COMPRESSION, $pos);
            }
            $block = substr($raw, $pos, $size);
            $pos += $size;
            if ($blockCheck !== 0) {
                if ($pos + 4 > $n) {
                    throw new ArrowException(Kind::COMPRESSION, $pos);
                }
                $got = unpack('V', substr($raw, $pos, 4))[1];
                $pos += 4;
                if ($got !== Xxh::xxh32($block)) {
                    throw new ArrowException(Kind::COMPRESSION, $pos);
                }
            }
            $blockAt = strlen($out);
            if ($stored) {
                $out .= $block;
            } else {
                $out .= self::decodeBlock($block, $independent === 1, $blockAt, $out);
            }
            if (strlen($out) > Limits::CODEC) {
                throw new ArrowException(Kind::COMPRESSION, $pos);
            }
        }
        if ($contentCheck !== 0) {
            if ($pos + 4 > $n) {
                throw new ArrowException(Kind::COMPRESSION, $pos);
            }
            $got = unpack('V', substr($raw, $pos, 4))[1];
            $pos += 4;
            if ($got !== Xxh::xxh32($out)) {
                throw new ArrowException(Kind::COMPRESSION, $pos);
            }
        }
        if ($contentSize >= 0 && $contentSize !== strlen($out)) {
            throw new ArrowException(Kind::COMPRESSION, 0);
        }
        if ($pos !== $n) {
            throw new ArrowException(Kind::COMPRESSION, $pos);
        }
        return $out;
    }

    private static function blockMax(int $code): int
    {
        return match ($code) {
            4 => 65536,
            5 => 262144,
            6 => 1048576,
            7 => 4194304,
            default => throw new ArrowException(Kind::COMPRESSION, 5),
        };
    }

    private static function writeBlock(string &$out, string $slice): void
    {
        $comp = self::compressBlock($slice);
        if (strlen($comp) >= strlen($slice)) {
            self::writeStored($out, $slice);
            return;
        }
        $out .= pack('V', strlen($comp)) . $comp;
    }

    private static function writeStored(string &$out, string $slice): void
    {
        $n = strlen($slice);
        $out .= pack('V', $n | 0x80000000) . $slice;
    }

    private static function compressBlock(string $raw): string
    {
        $n = strlen($raw);
        if ($n < 13) {
            return self::emitLiterals($raw, 0, $n);
        }
        $table = array_fill(0, 1 << self::HASH_LOG, -1);
        $anchor = 0;
        $i = 0;
        $out = '';
        while ($i <= $n - 12) {
            $h = self::hash4($raw, $i);
            $src = $table[$h];
            $table[$h] = $i;
            if ($src < 0 || $i - $src > 65535 || !self::match4($raw, $src, $i)) {
                $i++;
                continue;
            }
            $m = 4;
            $maxM = $n - 5 - $i;
            while ($m < $maxM && $raw[$src + $m] === $raw[$i + $m]) {
                $m++;
            }
            $out .= self::emitSequence($raw, $anchor, $i, $i - $src, $m);
            $i += $m;
            $anchor = $i;
        }
        $out .= self::emitLiterals($raw, $anchor, $n);
        return $out;
    }

    private static function match4(string $raw, int $a, int $b): bool
    {
        return $raw[$a] === $raw[$b]
            && $raw[$a + 1] === $raw[$b + 1]
            && $raw[$a + 2] === $raw[$b + 2]
            && $raw[$a + 3] === $raw[$b + 3];
    }

    private static function hash4(string $raw, int $i): int
    {
        $v = unpack('V', $raw, $i)[1];
        return (Bin::u32mul($v, 2654435761) >> (32 - self::HASH_LOG)) & ((1 << self::HASH_LOG) - 1);
    }

    private static function emitLiterals(string $raw, int $anchor, int $end): string
    {
        $lit = $end - $anchor;
        $tok = 0;
        $extra = '';
        if ($lit >= 15) {
            $tok = 15 << 4;
            $extra = self::extra($lit - 15);
        } else {
            $tok = $lit << 4;
        }
        return chr($tok) . $extra . substr($raw, $anchor, $lit);
    }

    private static function emitSequence(string $raw, int $anchor, int $at, int $offset, int $mlen): string
    {
        $lit = $at - $anchor;
        $ll = min($lit, 15);
        $ml = $mlen - 4;
        $mm = min($ml, 15);
        $out = chr(($ll << 4) | $mm);
        if ($lit >= 15) {
            $out .= self::extra($lit - 15);
        }
        $out .= substr($raw, $anchor, $lit);
        $out .= chr($offset & 255) . chr(($offset >> 8) & 255);
        if ($ml >= 15) {
            $out .= self::extra($ml - 15);
        }
        return $out;
    }

    private static function extra(int $value): string
    {
        $out = '';
        while ($value >= 255) {
            $out .= "\xff";
            $value -= 255;
        }
        return $out . chr($value);
    }

    private static function decodeBlock(string $src, bool $independent, int $blockAt, string $prefix): string
    {
        $i = 0;
        $n = strlen($src);
        $out = '';
        $prefixLen = strlen($prefix);
        while ($i < $n) {
            $token = ord($src[$i]);
            $i++;
            $lit = $token >> 4;
            if ($lit === 15) {
                while (true) {
                    if ($i >= $n) {
                        throw new ArrowException(Kind::COMPRESSION, $i);
                    }
                    $extra = ord($src[$i]);
                    $i++;
                    $lit += $extra;
                    if ($extra !== 255) {
                        break;
                    }
                }
            }
            if ($i + $lit > $n) {
                throw new ArrowException(Kind::COMPRESSION, $i);
            }
            if ($lit > 0) {
                $out .= substr($src, $i, $lit);
                $i += $lit;
            }
            if ($i >= $n) {
                break;
            }
            if ($i + 2 > $n) {
                throw new ArrowException(Kind::COMPRESSION, $i);
            }
            $offset = ord($src[$i]) | (ord($src[$i + 1]) << 8);
            $i += 2;
            $local = strlen($out);
            if ($offset <= 0 || $offset > $prefixLen + $local) {
                throw new ArrowException(Kind::COMPRESSION, $i);
            }
            if ($independent && $offset > $local) {
                throw new ArrowException(Kind::COMPRESSION, $i);
            }
            $mlen = ($token & 15) + 4;
            if (($token & 15) === 15) {
                while (true) {
                    if ($i >= $n) {
                        throw new ArrowException(Kind::COMPRESSION, $i);
                    }
                    $extra = ord($src[$i]);
                    $i++;
                    $mlen += $extra;
                    if ($extra !== 255) {
                        break;
                    }
                }
            }
            if ($offset <= $local) {
                $mpos = $local - $offset;
                if ($mlen <= $offset) {
                    $out .= substr($out, $mpos, $mlen);
                } elseif ($offset === 1) {
                    $out .= str_repeat($out[$mpos], $mlen);
                } else {
                    $chunk = substr($out, $mpos, $offset);
                    $left = $mlen;
                    while ($left > 0) {
                        $take = min($offset, $left);
                        $out .= ($take === $offset) ? $chunk : substr($chunk, 0, $take);
                        $left -= $take;
                    }
                }
            } else {
                $into = $offset - $local;
                $start = $prefixLen - $into;
                $fromPrefix = min($mlen, $into);
                $out .= substr($prefix, $start, $fromPrefix);
                $rest = $mlen - $fromPrefix;
                for ($k = 0; $k < $rest; $k++) {
                    $out .= $out[$k];
                }
            }
            if ($prefixLen + strlen($out) > Limits::CODEC) {
                throw new ArrowException(Kind::COMPRESSION, $i);
            }
        }
        return $out;
    }
}
