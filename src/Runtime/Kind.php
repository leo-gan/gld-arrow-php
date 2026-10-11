<?php

declare(strict_types=1);

namespace Gld\Arrow\Runtime;

final class Kind
{
    public const EOF = 'EOF';
    public const SYNTAX = 'SYNTAX';
    public const RANGE = 'RANGE';
    public const UTF8 = 'UTF8';
    public const TYPE = 'TYPE';
    public const DEPTH = 'DEPTH';
    public const SCHEMA = 'SCHEMA';
    public const VERSION = 'VERSION';
    public const COMPRESSION = 'COMPRESSION';
    public const FLIGHT = 'FLIGHT';
}

