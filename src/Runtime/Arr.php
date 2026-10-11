<?php

declare(strict_types=1);

namespace Gld\Arrow\Runtime;

final class Arr
{
    public int $field = -1;
    public int $length = 0;
    public int $nullCount = 0;
    /** @var list<int> */
    public array $bufs = [];
    /** @var list<int> */
    public array $children = [];
    public int $dict = -1;
}

