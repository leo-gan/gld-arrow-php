<?php

declare(strict_types=1);

namespace Gld\Arrow\Runtime;

final class Batch
{
    public function __construct(
        public int $length,
        /** @var list<int> */
        public array $cols,
        public int $codec = Codec::NONE,
    ) {
    }
}

