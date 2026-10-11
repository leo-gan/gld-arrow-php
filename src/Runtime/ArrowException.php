<?php

declare(strict_types=1);

namespace Gld\Arrow\Runtime;

final class ArrowException extends \RuntimeException
{
    public function __construct(
        public readonly string $kind,
        public readonly int $at = 0,
        string $detail = '',
    ) {
        $msg = $kind . ' at ' . $at;
        if ($detail !== '') {
            $msg .= ': ' . $detail;
        }
        parent::__construct($msg);
    }
}

