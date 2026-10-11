<?php

declare(strict_types=1);

namespace Gld\Arrow\Runtime;

final class Limits
{
    public const DEPTH = 64;
    public const BUFFER = 256 * 1024 * 1024;
    public const CODEC = 64 * 1024 * 1024;
}
