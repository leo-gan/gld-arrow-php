<?php

declare(strict_types=1);

namespace Gld\Arrow\Runtime;

final class Footer
{
    public int $version = Meta::V5;
    /** @var list<Block> */
    public array $dictionaries = [];
    /** @var list<Block> */
    public array $recordBatches = [];
}

