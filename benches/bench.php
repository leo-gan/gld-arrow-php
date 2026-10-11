<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Gld\Arrow\Arrow;
use Gld\Arrow\Runtime\TableBuilder;

$rows = 4096;
$ints = [];
$texts = [];
for ($i = 0; $i < $rows; $i++) {
    $ints[] = ($i % 17 === 0) ? null : $i;
    $texts[] = ($i % 19 === 0) ? null : 'row-' . ($i % 64);
}
$table = (new TableBuilder())->int64('id', $ints)->utf8('name', $texts)->build();

$iters = 30;
$encode = Arrow::writeStream($table);
$t0 = hrtime(true);
for ($i = 0; $i < $iters; $i++) {
    $encode = Arrow::writeStream($table);
}
$t1 = hrtime(true);
for ($i = 0; $i < $iters; $i++) {
    Arrow::readStream($encode);
}
$t2 = hrtime(true);

$encNs = (int) round(($t1 - $t0) / $iters);
$decNs = (int) round(($t2 - $t1) / $iters);
echo 'rows ' . $rows . PHP_EOL;
echo 'bytes ' . strlen($encode) . PHP_EOL;
echo 'encode_ns_per_op ' . $encNs . PHP_EOL;
echo 'decode_ns_per_op ' . $decNs . PHP_EOL;
echo 'roundtrip_ns_per_op ' . ($encNs + $decNs) . PHP_EOL;
