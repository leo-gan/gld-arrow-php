<?php

declare(strict_types=1);

namespace Gld\Arrow\Runtime;

final class Tensor
{
    public int $field = -1;
    /** @var list<int> */
    public array $shape = [];
    /** @var list<string> */
    public array $shapeNames = [];
    /** @var list<int> */
    public array $strides = [];
    public int $dataBuf = -1;
    /** 0 dense, 1 COO, 2 CSX, 3 CSF. */
    public int $sparseKind = 0;
    /** @var list<int> */
    public array $indexBufs = [];
    public int $nz = 0;
    public bool $cooCanonical = false;
    public int $csxAxis = 0;
    public int $indexWidth = 64;
    public bool $indexSigned = true;
    /** @var list<int> */
    public array $axisOrder = [];
}

