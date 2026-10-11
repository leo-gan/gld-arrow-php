<?php

declare(strict_types=1);

namespace Gld\Arrow\Runtime;

final class Type
{
    public const T_NULL = 1;
    public const T_BOOL = 2;
    public const T_INT = 3;
    public const T_FLOAT = 4;
    public const T_BINARY = 5;
    public const T_UTF8 = 6;
    public const T_DECIMAL = 7;
    public const T_DATE = 8;
    public const T_TIME = 9;
    public const T_TIMESTAMP = 10;
    public const T_INTERVAL = 11;
    public const T_LIST = 12;
    public const T_STRUCT = 13;
    public const T_UNION = 14;
    public const T_FIXED_BINARY = 15;
    public const T_FIXED_LIST = 16;
    public const T_MAP = 17;
    public const T_DURATION = 18;
    public const T_LARGE_BINARY = 19;
    public const T_LARGE_UTF8 = 20;
    public const T_LARGE_LIST = 21;
    public const T_RUN_END = 22;
    public const T_BINARY_VIEW = 23;
    public const T_UTF8_VIEW = 24;
    public const T_LIST_VIEW = 25;
    public const T_LARGE_LIST_VIEW = 26;
}

