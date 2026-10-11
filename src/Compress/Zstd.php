<?php

declare(strict_types=1);

namespace Gld\Arrow\Compress;

use Gld\Arrow\Runtime\ArrowException;
use Gld\Arrow\Runtime\Kind;
use Gld\Arrow\Runtime\Limits;

/**
 * Zstd frames. The writer emits raw blocks and RLE blocks, which are legal
 * Zstd frames. The reader accepts those, skippable frames, and content checksums.
 * Huffman literals and FSE sequence blocks are rejected.
 */
final class Zstd
{
    private const MAGIC = 0xFD2FB528;
    private const BLOCK = 131072;

    public static function compress(string $raw): string
    {
        $n = strlen($raw);
        if ($n > Limits::CODEC) {
            throw new ArrowException(Kind::COMPRESSION, $n);
        }
        $out = pack('V', self::MAGIC);
        if ($n <= 255) {
            $out .= "\x20" . chr($n);
        } elseif ($n <= 65791) {
            $adj = $n - 256;
            $out .= "\x60" . pack('v', $adj);
        } else {
            $out .= "\xA0" . pack('V', $n);
        }
        if ($n === 0) {
            $out .= "\x01\x00\x00";
            return $out;
        }
        $uniform = true;
        $v0 = $raw[0];
        for ($t = 1; $t < $n; $t++) {
            if ($raw[$t] !== $v0) {
                $uniform = false;
                break;
            }
        }
        $off = 0;
        while ($off < $n) {
            $take = min(self::BLOCK, $n - $off);
            $last = ($off + $take === $n) ? 1 : 0;
            if ($uniform) {
                $hdr = ($take << 3) | 2 | $last;
                $out .= chr($hdr & 255) . chr(($hdr >> 8) & 255) . chr(($hdr >> 16) & 255);
                $out .= $raw[$off];
            } else {
                $hdr = ($take << 3) | $last;
                $out .= chr($hdr & 255) . chr(($hdr >> 8) & 255) . chr(($hdr >> 16) & 255);
                $out .= substr($raw, $off, $take);
            }
            $off += $take;
        }
        return $out;
    }

    public static function decompress(string $raw): string
    {
        $pos = 0;
        $n = strlen($raw);
        $out = '';
        while ($pos < $n) {
            if ($pos + 4 > $n) {
                throw new ArrowException(Kind::COMPRESSION, $pos);
            }
            $magic = unpack('V', substr($raw, $pos, 4))[1];
            if ($magic >= 0x184D2A50 && $magic <= 0x184D2A5F) {
                $pos += 4;
                if ($pos + 4 > $n) {
                    throw new ArrowException(Kind::COMPRESSION, $pos);
                }
                $skip = unpack('V', substr($raw, $pos, 4))[1];
                $pos += 4;
                if ($pos + $skip > $n) {
                    throw new ArrowException(Kind::COMPRESSION, $pos);
                }
                $pos += $skip;
                continue;
            }
            if ($magic !== self::MAGIC) {
                throw new ArrowException(Kind::COMPRESSION, $pos);
            }
            $pos += 4;
            $pos = self::frame($raw, $pos, $out);
            if (strlen($out) > Limits::CODEC) {
                throw new ArrowException(Kind::COMPRESSION, $pos);
            }
        }
        return $out;
    }

    private static function frame(string $raw, int $pos, string &$out): int
    {
        $n = strlen($raw);
        if ($pos >= $n) {
            throw new ArrowException(Kind::COMPRESSION, $pos);
        }
        $desc = ord($raw[$pos]);
        $pos++;
        if ((($desc >> 3) & 1) !== 0 || (($desc >> 4) & 1) !== 0) {
            throw new ArrowException(Kind::COMPRESSION, $pos);
        }
        $fcsFlag = $desc >> 6;
        $single = ($desc >> 5) & 1;
        $checksum = ($desc >> 2) & 1;
        $dictFlag = $desc & 3;
        if ($single === 0) {
            if ($pos >= $n) {
                throw new ArrowException(Kind::COMPRESSION, $pos);
            }
            $pos++;
        }
        $dictBytes = match ($dictFlag) {
            0 => 0,
            1 => 1,
            2 => 2,
            default => 4,
        };
        if ($dictBytes !== 0) {
            if ($pos + $dictBytes > $n) {
                throw new ArrowException(Kind::COMPRESSION, $pos);
            }
            $id = 0;
            for ($k = 0; $k < $dictBytes; $k++) {
                $id |= ord($raw[$pos + $k]) << (8 * $k);
            }
            $pos += $dictBytes;
            if ($id !== 0) {
                throw new ArrowException(Kind::COMPRESSION, $pos, 'dictionary');
            }
        }
        $contentSize = -1;
        if ($fcsFlag === 0 && $single === 1) {
            if ($pos >= $n) {
                throw new ArrowException(Kind::COMPRESSION, $pos);
            }
            $contentSize = ord($raw[$pos]);
            $pos++;
        } elseif ($fcsFlag === 1) {
            if ($pos + 2 > $n) {
                throw new ArrowException(Kind::COMPRESSION, $pos);
            }
            $contentSize = unpack('v', substr($raw, $pos, 2))[1] + 256;
            $pos += 2;
        } elseif ($fcsFlag === 2) {
            if ($pos + 4 > $n) {
                throw new ArrowException(Kind::COMPRESSION, $pos);
            }
            $contentSize = unpack('V', substr($raw, $pos, 4))[1];
            $pos += 4;
        } elseif ($fcsFlag === 3) {
            if ($pos + 8 > $n) {
                throw new ArrowException(Kind::COMPRESSION, $pos);
            }
            $contentSize = unpack('q', substr($raw, $pos, 8))[1];
            $pos += 8;
            if ($contentSize < 0) {
                throw new ArrowException(Kind::COMPRESSION, $pos);
            }
        }
        $start = strlen($out);
        while (true) {
            if ($pos + 3 > $n) {
                throw new ArrowException(Kind::COMPRESSION, $pos);
            }
            $hdr = ord($raw[$pos]) | (ord($raw[$pos + 1]) << 8) | (ord($raw[$pos + 2]) << 16);
            $pos += 3;
            $last = $hdr & 1;
            $type = ($hdr >> 1) & 3;
            $size = $hdr >> 3;
            if ($type === 0) {
                if ($pos + $size > $n) {
                    throw new ArrowException(Kind::COMPRESSION, $pos);
                }
                $out .= substr($raw, $pos, $size);
                $pos += $size;
            } elseif ($type === 1) {
                if ($pos >= $n) {
                    throw new ArrowException(Kind::COMPRESSION, $pos);
                }
                $out .= str_repeat($raw[$pos], $size);
                $pos++;
            } elseif ($type === 2) {
                throw new ArrowException(Kind::COMPRESSION, $pos, 'zstd compressed block');
            } else {
                throw new ArrowException(Kind::COMPRESSION, $pos);
            }
            if (strlen($out) - $start > Limits::CODEC) {
                throw new ArrowException(Kind::COMPRESSION, $pos);
            }
            if ($last === 1) {
                break;
            }
        }
        $produced = strlen($out) - $start;
        if ($contentSize >= 0 && $contentSize !== $produced) {
            throw new ArrowException(Kind::COMPRESSION, $pos);
        }
        if ($checksum !== 0) {
            if ($pos + 4 > $n) {
                throw new ArrowException(Kind::COMPRESSION, $pos);
            }
            $got = unpack('V', substr($raw, $pos, 4))[1];
            $pos += 4;
            $expect = Xxh::xxh64Low32(substr($out, $start));
            if ($got !== $expect) {
                throw new ArrowException(Kind::COMPRESSION, $pos);
            }
        }
        return $pos;
    }
}
