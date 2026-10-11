<?php

declare(strict_types=1);

namespace Gld\Arrow\Compress;

use Gld\Arrow\Runtime\ArrowException;
use Gld\Arrow\Runtime\Codec;
use Gld\Arrow\Runtime\Kind;

final class Frame
{
    public static function compress(int $codec, string $raw): string
    {
        if ($codec === Codec::LZ4) {
            return Lz4::compress($raw);
        }
        if ($codec === Codec::ZSTD) {
            return Zstd::compress($raw);
        }
        throw new ArrowException(Kind::COMPRESSION, $codec);
    }

    public static function decompress(int $codec, string $raw): string
    {
        if ($codec === Codec::LZ4) {
            return Lz4::decompress($raw);
        }
        if ($codec === Codec::ZSTD) {
            return Zstd::decompress($raw);
        }
        throw new ArrowException(Kind::COMPRESSION, $codec);
    }
}
