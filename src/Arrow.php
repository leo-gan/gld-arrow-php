<?php

declare(strict_types=1);

namespace Gld\Arrow;

use Gld\Arrow\Runtime\Columnar;
use Gld\Arrow\Wire\Ipc;
use Gld\Arrow\Wire\TensorIo;

final class Arrow
{
    public static function writeStream(Columnar $c): string
    {
        return Ipc::writeStream($c);
    }

    public static function readStream(string $bytes): Columnar
    {
        return Ipc::readStream($bytes);
    }

    public static function writeFile(Columnar $c): string
    {
        return Ipc::writeFile($c);
    }

    public static function readFile(string $bytes): Columnar
    {
        return Ipc::readFile($bytes);
    }

    public static function writeTensors(Columnar $c): string
    {
        return TensorIo::write($c);
    }

    public static function readTensors(string $bytes): Columnar
    {
        return Ipc::readStream($bytes);
    }
}
