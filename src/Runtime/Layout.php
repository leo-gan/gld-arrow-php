<?php

declare(strict_types=1);

namespace Gld\Arrow\Runtime;

final class Layout
{
    public static function code(int $type): int
    {
        return match ($type) {
            Type::T_NULL => 1,
            Type::T_INT => 2,
            Type::T_FLOAT => 3,
            Type::T_BINARY => 4,
            Type::T_UTF8 => 5,
            Type::T_BOOL => 6,
            Type::T_DECIMAL => 7,
            Type::T_DATE => 8,
            Type::T_TIME => 9,
            Type::T_TIMESTAMP => 10,
            Type::T_INTERVAL => 11,
            Type::T_LIST => 12,
            Type::T_STRUCT => 13,
            Type::T_UNION => 14,
            Type::T_FIXED_BINARY => 15,
            Type::T_FIXED_LIST => 16,
            Type::T_MAP => 17,
            Type::T_DURATION => 18,
            Type::T_LARGE_BINARY => 19,
            Type::T_LARGE_UTF8 => 20,
            Type::T_LARGE_LIST => 21,
            Type::T_RUN_END => 22,
            Type::T_BINARY_VIEW => 23,
            Type::T_UTF8_VIEW => 24,
            Type::T_LIST_VIEW => 25,
            Type::T_LARGE_LIST_VIEW => 26,
            default => 0,
        };
    }

    public static function fromCode(int $code): int
    {
        $type = self::codeInverse($code);
        if ($type === 0) {
            throw new ArrowException(Kind::TYPE, $code);
        }
        return $type;
    }

    private static function codeInverse(int $code): int
    {
        return match ($code) {
            1 => Type::T_NULL,
            2 => Type::T_INT,
            3 => Type::T_FLOAT,
            4 => Type::T_BINARY,
            5 => Type::T_UTF8,
            6 => Type::T_BOOL,
            7 => Type::T_DECIMAL,
            8 => Type::T_DATE,
            9 => Type::T_TIME,
            10 => Type::T_TIMESTAMP,
            11 => Type::T_INTERVAL,
            12 => Type::T_LIST,
            13 => Type::T_STRUCT,
            14 => Type::T_UNION,
            15 => Type::T_FIXED_BINARY,
            16 => Type::T_FIXED_LIST,
            17 => Type::T_MAP,
            18 => Type::T_DURATION,
            19 => Type::T_LARGE_BINARY,
            20 => Type::T_LARGE_UTF8,
            21 => Type::T_LARGE_LIST,
            22 => Type::T_RUN_END,
            23 => Type::T_BINARY_VIEW,
            24 => Type::T_UTF8_VIEW,
            25 => Type::T_LIST_VIEW,
            26 => Type::T_LARGE_LIST_VIEW,
            default => 0,
        };
    }

    public static function localBufs(Field $f): int
    {
        return match ($f->type) {
            Type::T_NULL, Type::T_RUN_END => 0,
            Type::T_STRUCT, Type::T_FIXED_LIST => 1,
            Type::T_UNION => $f->unionMode === 1 ? 2 : 1,
            Type::T_LIST, Type::T_LARGE_LIST, Type::T_MAP => 2,
            Type::T_LIST_VIEW, Type::T_LARGE_LIST_VIEW => 3,
            Type::T_BINARY, Type::T_UTF8, Type::T_LARGE_BINARY, Type::T_LARGE_UTF8 => 3,
            Type::T_BINARY_VIEW, Type::T_UTF8_VIEW => 2,
            default => 2,
        };
    }

    public static function variadic(int $type): bool
    {
        return $type === Type::T_BINARY_VIEW || $type === Type::T_UTF8_VIEW;
    }

    public static function byteWidth(Field $f): int
    {
        return match ($f->type) {
            Type::T_INT => intdiv($f->bitWidth, 8),
            Type::T_FLOAT => match ($f->unit) {
                0 => 2,
                1 => 4,
                default => 8,
            },
            Type::T_DECIMAL => intdiv($f->bitWidth, 8),
            Type::T_DATE => $f->unit === 0 ? 4 : 8,
            Type::T_TIME => $f->unit <= 1 ? 4 : 8,
            Type::T_TIMESTAMP, Type::T_DURATION => 8,
            Type::T_INTERVAL => match ($f->unit) {
                0 => 4,
                1 => 8,
                default => 16,
            },
            Type::T_FIXED_BINARY => $f->fixedSize,
            default => 0,
        };
    }

    /** @param list<bool> $isNull */
    public static function validity(array $isNull): string
    {
        $nulls = 0;
        foreach ($isNull as $n) {
            if ($n) {
                $nulls++;
            }
        }
        if ($nulls === 0) {
            return '';
        }
        $n = count($isNull);
        $bytes = str_repeat("\0", intdiv($n + 7, 8));
        for ($i = 0; $i < $n; $i++) {
            if (!$isNull[$i]) {
                $bi = intdiv($i, 8);
                $bytes[$bi] = chr(ord($bytes[$bi]) | (1 << ($i & 7)));
            }
        }
        return $bytes;
    }

    public static function nullCount(string $validity, int $length): int
    {
        if ($validity === '' || $length === 0) {
            return 0;
        }
        $nulls = 0;
        for ($i = 0; $i < $length; $i++) {
            $bit = (ord($validity[intdiv($i, 8)]) >> ($i & 7)) & 1;
            if ($bit === 0) {
                $nulls++;
            }
        }
        return $nulls;
    }

    public static function validAt(string $validity, int $i, int $length): bool
    {
        if ($validity === '') {
            return true;
        }
        if ($i < 0 || $i >= $length) {
            return false;
        }
        $byte = intdiv($i, 8);
        if ($byte >= strlen($validity)) {
            return true;
        }
        return ((ord($validity[$byte]) >> ($i & 7)) & 1) === 1;
    }

    /** @param list<int|null> $values */
    public static function packInts(array $values, int $width): string
    {
        $out = '';
        foreach ($values as $v) {
            $n = $v ?? 0;
            $out .= match ($width) {
                1 => chr($n & 255),
                2 => pack('v', $n),
                4 => pack('V', $n),
                8 => pack('q', $n),
                default => throw new ArrowException(Kind::TYPE, $width, 'int width'),
            };
        }
        return $out;
    }

    /** @return list<int> */
    public static function unpackInts(string $raw, int $width, int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $o = $i * $width;
            Bin::need($raw, $o, $width);
            $out[] = match ($width) {
                1 => ord($raw[$o]),
                2 => Bin::u16($raw, $o),
                4 => Bin::i32($raw, $o),
                8 => Bin::i64($raw, $o),
                default => throw new ArrowException(Kind::TYPE, $width),
            };
        }
        return $out;
    }

    public static function signExtend(int $v, int $width): string
    {
        if ($width <= 0 || $width > 32) {
            throw new ArrowException(Kind::TYPE, $width, 'decimal width');
        }
        $raw = pack('q', $v);
        if ($width <= 8) {
            return substr($raw, 0, $width);
        }
        $pad = $v < 0 ? "\xff" : "\x00";
        return $raw . str_repeat($pad, $width - 8);
    }
}
