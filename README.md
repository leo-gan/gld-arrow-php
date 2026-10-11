# gld-arrow-php

A from-scratch Apache Arrow library for PHP 8.3.
The runtime and the code generator are written in PHP. They do not wrap, link,
or call the Arrow C++ library, `ext-arrow`, or any other C Arrow extension.

PyArrow is a **test oracle** only. It is not required to encode or decode at
runtime. Checked-in streams under `testdata/golden/` were written by PyArrow
25 so the test suite can read them without installing PyArrow.

This repository is a standalone library. It is not part of any other project.

Documentation: [Why Arrow](https://leo-gan.github.io/gld-arrow-php/why-arrow/),
[Instructions](https://leo-gan.github.io/gld-arrow-php/instructions/),
[Examples](https://leo-gan.github.io/gld-arrow-php/examples/),
[Techniques](https://leo-gan.github.io/gld-arrow-php/techniques/),
[Test data](https://leo-gan.github.io/gld-arrow-php/test-data/).

## Install

PHP 8.3 or newer. From a git checkout:

```bash
git clone https://github.com/leo-gan/gld-arrow-php.git
cd gld-arrow-php
php tests/run.php
```

Composer package name: `leo-gan/gld-arrow-php`. Namespace: `Gld\Arrow`.
The code generator is `gld-arrowgen-php`.

## What it reads and writes

| Surface | Behavior |
| --- | --- |
| IPC stream and file | Schema, dictionary batches, record batches, and the file footer. Metadata versions V4 and V5 are accepted. The writer emits V5. |
| Types | Null, bool, ints 8/16/32/64 signed and unsigned, float16/32/64, decimal, date, time, timestamp, duration, interval, binary, large binary, string, large string, utf8 view, binary view, fixed-size binary, list, large list, list view, large list view, fixed-size list, struct, map, dense union, sparse union, dictionary, and run-end encoded. |
| Compression | LZ4 frame and Zstd body compression, implemented in PHP. The Zstd writer emits raw blocks and RLE blocks. The reader accepts those, skippable frames, and content checksums. |
| Tensors | Dense tensor messages, plus sparse COO, CSX, and CSF messages. |
| Flight | Handshake, DoGet, and DoPut protobuf messages, plus an in-memory request/response loop that does not open a socket. |
| Schema | JSON Schema objects and Arrow schema JSON. `gld-arrowgen-php` emits PHP classes with `encodedLength`, `encode`, and `decode`. |

The writer uses little-endian buffers and 8-byte alignment. Two encodes of the
same value match when the options match. Nulls, field order, and schema
metadata are kept. A big-endian schema is accepted on read and fixed-width
value buffers are swapped into little-endian memory.

## Layout

`src/Arrow.php` is the public facade. `src/Runtime` holds the columnar arena
and the FlatBuffer codec. `src/Wire` holds IPC, tensors, and the file footer.
`src/Compress` holds LZ4 and Zstd. `src/Flight` holds the protobuf messages.
`src/Schema` and `src/Codegen` turn a schema into PHP.

## License

MIT. Copyright (c) 2026 Leonid Ganeline.
