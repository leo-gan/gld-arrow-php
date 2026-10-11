<?php

declare(strict_types=1);

namespace Gld\Arrow\Runtime;

final class DictRef
{
    public function __construct(
        public int $id,
        public int $array,
        public bool $isDelta = false,
    ) {
    }
}

