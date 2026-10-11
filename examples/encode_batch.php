<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Gld\Arrow\Arrow;
use Gld\Arrow\Runtime\TableBuilder;

$batch = (new TableBuilder())
    ->int32('a', [1, null, 3])
    ->build();
$bytes = Arrow::writeStream($batch);
echo 'stream bytes ' . strlen($bytes) . PHP_EOL;
