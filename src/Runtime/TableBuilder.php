<?php

declare(strict_types=1);

namespace Gld\Arrow\Runtime;

final class TableBuilder
{
    private Columnar $c;
    /** @var list<int> */
    private array $cols = [];
    private int $rows = -1;
    private int $codec = Codec::NONE;
    private int $nextDict = 0;

    public function __construct()
    {
        $this->c = new Columnar();
    }

    public function codec(int $codec): self
    {
        $this->codec = $codec;
        $this->c->codec = $codec;
        return $this;
    }

    public function meta(string $key, string $value): self
    {
        $this->c->metadata[] = [$key, $value];
        return $this;
    }

    public function addField(Field $field): int
    {
        return $this->c->addField($field);
    }

    /** @param list<string> $bufs @param list<int> $children */
    public function addArray(int $field, int $length, int $nullCount, array $bufs, array $children = []): int
    {
        $a = new Arr();
        $a->field = $field;
        $a->length = $length;
        $a->nullCount = $nullCount;
        foreach ($bufs as $buf) {
            $a->bufs[] = $this->c->addBuf($buf);
        }
        $a->children = $children;
        return $this->c->addArray($a);
    }

    public function column(int $arrayId): self
    {
        $this->cols[] = $arrayId;
        $len = $this->c->arrays[$arrayId]->length;
        if ($this->rows < 0) {
            $this->rows = $len;
        } elseif ($this->rows !== $len) {
            throw new ArrowException(Kind::SCHEMA, $len, 'column length');
        }
        $field = $this->c->arrays[$arrayId]->field;
        if (!in_array($field, $this->c->top, true)) {
            $this->c->addTop($field);
        }
        return $this;
    }

    /** @param list<int|null> $values */
    public function int32(string $name, array $values, bool $nullable = true): self
    {
        return $this->ints($name, $values, 32, true, $nullable);
    }

    /** @param list<int|null> $values */
    public function int64(string $name, array $values, bool $nullable = true): self
    {
        return $this->ints($name, $values, 64, true, $nullable);
    }

    /** @param list<int|null> $values */
    public function ints(string $name, array $values, int $width, bool $signed, bool $nullable): self
    {
        $this->expect(count($values));
        $nulls = [];
        foreach ($values as $v) {
            $nulls[] = $v === null;
        }
        $fid = $this->addField(Field::int($name, $width, $signed, $nullable));
        $aid = $this->addArray($fid, count($values), Layout::nullCount(Layout::validity($nulls), count($values)), [
            Layout::validity($nulls),
            Layout::packInts($values, intdiv($width, 8)),
        ]);
        return $this->column($aid);
    }

    /** @param list<string|null> $values */
    public function utf8(string $name, array $values, bool $nullable = true, bool $large = false): self
    {
        $this->expect(count($values));
        $nulls = [];
        $data = '';
        $offs = $large ? [pack('q', 0)] : [pack('V', 0)];
        $cursor = 0;
        foreach ($values as $v) {
            $nulls[] = $v === null;
            if ($v !== null) {
                Bin::utf8($v);
                $data .= $v;
                $cursor += strlen($v);
            }
            $offs[] = $large ? pack('q', $cursor) : pack('V', $cursor);
        }
        $valid = Layout::validity($nulls);
        $fid = $this->addField(Field::utf8($name, $nullable, $large));
        $aid = $this->addArray($fid, count($values), Layout::nullCount($valid, count($values)), [
            $valid,
            implode('', $offs),
            $data,
        ]);
        return $this->column($aid);
    }

    /** @param list<bool|null> $values */
    public function bools(string $name, array $values, bool $nullable = true): self
    {
        $this->expect(count($values));
        $nulls = [];
        $bits = str_repeat("\0", intdiv(count($values) + 7, 8));
        foreach ($values as $i => $v) {
            $nulls[] = $v === null;
            if ($v) {
                $bits[intdiv($i, 8)] = chr(ord($bits[intdiv($i, 8)]) | (1 << ($i & 7)));
            }
        }
        if (count($values) === 0) {
            $bits = '';
        }
        $valid = Layout::validity($nulls);
        $fid = $this->addField(Field::bool($name, $nullable));
        $aid = $this->addArray($fid, count($values), Layout::nullCount($valid, count($values)), [$valid, $bits]);
        return $this->column($aid);
    }

    /** @param list<float|null> $values */
    public function float64(string $name, array $values, bool $nullable = true): self
    {
        $this->expect(count($values));
        $nulls = [];
        $raw = '';
        foreach ($values as $v) {
            $nulls[] = $v === null;
            $raw .= pack('e', $v ?? 0.0);
        }
        $valid = Layout::validity($nulls);
        $f = Field::named($name, Type::T_FLOAT, $nullable);
        $f->unit = 2;
        $fid = $this->addField($f);
        $aid = $this->addArray($fid, count($values), Layout::nullCount($valid, count($values)), [$valid, $raw]);
        return $this->column($aid);
    }

    /** @param list<list<int|null>|null> $rows */
    public function listInt32(string $name, array $rows): self
    {
        $this->expect(count($rows));
        $flat = [];
        foreach ($rows as $row) {
            if ($row === null) {
                continue;
            }
            foreach ($row as $v) {
                $flat[] = $v;
            }
        }
        $child = Field::int('item', 32, true, true);
        $cid = $this->addField($child);
        $cnulls = [];
        foreach ($flat as $v) {
            $cnulls[] = $v === null;
        }
        $cvalid = Layout::validity($cnulls);
        $caid = $this->addArray($cid, count($flat), Layout::nullCount($cvalid, count($flat)), [
            $cvalid,
            Layout::packInts($flat, 4),
        ]);
        $nulls = [];
        $offs = [0];
        $cursor = 0;
        foreach ($rows as $row) {
            $nulls[] = $row === null;
            if ($row !== null) {
                $cursor += count($row);
            }
            $offs[] = $cursor;
        }
        $valid = Layout::validity($nulls);
        $parent = Field::nested($name, Type::T_LIST, [$cid], true);
        $pid = $this->addField($parent);
        $raw = '';
        foreach ($offs as $o) {
            $raw .= pack('V', $o);
        }
        $aid = $this->addArray($pid, count($rows), Layout::nullCount($valid, count($rows)), [$valid, $raw], [$caid]);
        return $this->column($aid);
    }

    /**
     * @param list<array{x:int|null,y:string|null}|null> $rows
     */
    public function structIntUtf8(string $name, array $rows): self
    {
        $this->expect(count($rows));
        $xs = [];
        $ys = [];
        $nulls = [];
        foreach ($rows as $row) {
            $nulls[] = $row === null;
            $xs[] = $row['x'] ?? null;
            $ys[] = $row['y'] ?? null;
        }
        $xf = Field::int('x', 32, true, true);
        $xid = $this->addField($xf);
        $xnull = [];
        foreach ($xs as $v) {
            $xnull[] = $v === null;
        }
        $xv = Layout::validity($xnull);
        $xa = $this->addArray($xid, count($rows), Layout::nullCount($xv, count($rows)), [$xv, Layout::packInts($xs, 4)]);
        $yf = Field::utf8('y', true);
        $yid = $this->addField($yf);
        $ynull = [];
        $data = '';
        $offs = pack('V', 0);
        $cursor = 0;
        foreach ($ys as $v) {
            $ynull[] = $v === null;
            if ($v !== null) {
                $data .= $v;
                $cursor += strlen($v);
            }
            $offs .= pack('V', $cursor);
        }
        $yv = Layout::validity($ynull);
        $ya = $this->addArray($yid, count($rows), Layout::nullCount($yv, count($rows)), [$yv, $offs, $data]);
        $valid = Layout::validity($nulls);
        $sf = Field::nested($name, Type::T_STRUCT, [$xid, $yid], true);
        $sid = $this->addField($sf);
        $aid = $this->addArray($sid, count($rows), Layout::nullCount($valid, count($rows)), [$valid], [$xa, $ya]);
        return $this->column($aid);
    }

    /**
     * @param list<int|null> $indices
     * @param list<string|null> $dictionary
     */
    public function dictUtf8(string $name, array $indices, array $dictionary): self
    {
        $this->expect(count($indices));
        $f = Field::utf8($name, true);
        $f->dictId = $this->nextDict;
        $this->nextDict++;
        $f->dictIndexWidth = 32;
        $f->dictIndexSigned = true;
        $fid = $this->addField($f);
        $dnull = [];
        $data = '';
        $offs = pack('V', 0);
        $cursor = 0;
        foreach ($dictionary as $v) {
            $dnull[] = $v === null;
            if ($v !== null) {
                $data .= $v;
                $cursor += strlen($v);
            }
            $offs .= pack('V', $cursor);
        }
        $dv = Layout::validity($dnull);
        $da = $this->addArray($fid, count($dictionary), Layout::nullCount($dv, count($dictionary)), [$dv, $offs, $data]);
        $this->c->addDict($f->dictId, $da, false);
        $inull = [];
        foreach ($indices as $v) {
            $inull[] = $v === null;
        }
        $iv = Layout::validity($inull);
        $ia = $this->addArray($fid, count($indices), Layout::nullCount($iv, count($indices)), [
            $iv,
            Layout::packInts($indices, 4),
        ]);
        return $this->column($ia);
    }

    public function build(): Columnar
    {
        $rows = $this->rows < 0 ? 0 : $this->rows;
        $this->c->addBatch($rows, $this->cols, $this->codec);
        return $this->c;
    }

    private function expect(int $n): void
    {
        if ($this->rows < 0) {
            $this->rows = $n;
            return;
        }
        if ($this->rows !== $n) {
            throw new ArrowException(Kind::SCHEMA, $n, 'column length');
        }
    }
}
