<?php

declare(strict_types=1);

namespace Gld\Arrow\Generated;

use Gld\Arrow\Runtime\TableBuilder;
use Gld\Arrow\Runtime\Values;
use Gld\Arrow\Wire\Ipc;

final class Message
{
    public function __construct(
        public int $id = 0,
        public string $name = ''
    ) {
    }

    public function encodedLength(): int
    {
        return strlen($this->encode());
    }

    public function encode(): string
    {
        $b = new TableBuilder();
        $b->ints('id', [$this->id], 64, true, false);
        $b->utf8('name', [$this->name], false);
        return Ipc::writeStream($b->build());
    }

    public static function decode(string $bytes): self
    {
        $table = Ipc::readStream($bytes);
        $row = Values::row($table, 0, 0);
        return new self(
            $row['id'],
            $row['name'],
        );
    }
}
