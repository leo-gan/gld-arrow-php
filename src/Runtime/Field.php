<?php

declare(strict_types=1);

namespace Gld\Arrow\Runtime;

final class Field
{
    public string $name = '';
    public bool $nullable = true;
    public int $type = Type::T_NULL;
    public int $bitWidth = 0;
    public bool $signed = true;
    public int $precision = 0;
    public int $scale = 0;
    public int $unit = 0;
    public string $timezone = '';
    /** 0 sparse, 1 dense. */
    public int $unionMode = 0;
    /** @var list<int> */
    public array $typeIds = [];
    /** @var list<int> */
    public array $children = [];
    /** @var list<array{0:string,1:string}> */
    public array $metadata = [];
    public int $dictId = -1;
    public int $dictIndexWidth = 32;
    public bool $dictIndexSigned = true;
    public bool $dictOrdered = false;
    public bool $keysSorted = false;
    public int $fixedSize = 0;

    public static function named(string $name, int $type, bool $nullable = true): self
    {
        $f = new self();
        $f->name = $name;
        $f->type = $type;
        $f->nullable = $nullable;
        return $f;
    }

    public static function int(string $name, int $width, bool $signed = true, bool $nullable = true): self
    {
        $f = self::named($name, Type::T_INT, $nullable);
        $f->bitWidth = $width;
        $f->signed = $signed;
        return $f;
    }

    public static function utf8(string $name, bool $nullable = true, bool $large = false): self
    {
        return self::named($name, $large ? Type::T_LARGE_UTF8 : Type::T_UTF8, $nullable);
    }

    public static function bool(string $name, bool $nullable = true): self
    {
        return self::named($name, Type::T_BOOL, $nullable);
    }

    /** @param list<int> $children */
    public static function nested(string $name, int $type, array $children, bool $nullable = true): self
    {
        $f = self::named($name, $type, $nullable);
        $f->children = $children;
        return $f;
    }
}

