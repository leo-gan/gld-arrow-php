<?php

declare(strict_types=1);

namespace Gld\Arrow\Runtime;

final class Block
{
    public function __construct(
        public int $offset,
        public int $metaLength,
        public int $bodyLength,
    ) {
    }
}

