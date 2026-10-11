<?php

declare(strict_types=1);

namespace Gld\Arrow\Runtime;

final class Columnar
{
    /** @var list<Field> */
    public array $fields = [];
    /** @var list<int> */
    public array $top = [];
    public int $endian = 0;
    /** @var list<array{0:string,1:string}> */
    public array $metadata = [];
    public string $bytes = '';
    /** @var list<array{off:int,len:int}> */
    public array $bufs = [];
    /** @var list<Arr> */
    public array $arrays = [];
    /** @var list<Batch> */
    public array $batches = [];
    /** @var list<DictRef> */
    public array $dicts = [];
    /** @var list<Tensor> */
    public array $tensors = [];
    public int $codec = Codec::NONE;
    public ?Footer $footer = null;

    public function addField(Field $f): int
    {
        $this->fields[] = $f;
        return count($this->fields) - 1;
    }

    public function addTop(int $id): void
    {
        $this->top[] = $id;
    }

    public function addBuf(string $raw): int
    {
        Bin::checkBuffer(strlen($raw));
        $id = count($this->bufs);
        if ($raw === '') {
            $this->bufs[] = ['off' => 0, 'len' => 0];
            return $id;
        }
        $off = strlen($this->bytes);
        $this->bytes .= $raw;
        $this->bufs[] = ['off' => $off, 'len' => strlen($raw)];
        return $id;
    }

    public function buf(int $id): string
    {
        $b = $this->bufs[$id];
        if ($b['len'] === 0) {
            return '';
        }
        return substr($this->bytes, $b['off'], $b['len']);
    }

    public function addArray(Arr $a): int
    {
        $this->arrays[] = $a;
        return count($this->arrays) - 1;
    }

    /** @param list<int> $cols */
    public function addBatch(int $length, array $cols, int $codec = Codec::NONE): void
    {
        $this->batches[] = new Batch($length, $cols, $codec);
    }

    public function addDict(int $id, int $array, bool $delta = false): void
    {
        $this->dicts[] = new DictRef($id, $array, $delta);
    }
}

