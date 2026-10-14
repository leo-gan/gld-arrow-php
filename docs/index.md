# gld-arrow-php

gld-arrow-php is a from-scratch [Apache Arrow](https://arrow.apache.org/docs/format/index.html) library for PHP 8.3.

The runtime reads and writes the Arrow columnar format, the IPC stream, the IPC file, tensors, and the Flight protocol messages used by handshake, DoGet, and DoPut. A code generator turns a JSON Schema document or an Arrow schema into a PHP class. None of this code wraps a C, C++, or Rust Arrow library.

| Page | What it explains |
| --- | --- |
| [Why Arrow](why-arrow.md) | Columnar values, IPC, and Flight. |
| [Instructions](instructions.md) | Install, import, and run the tests. |
| [Examples](examples.md) | A record batch and a generated class. |
| [Techniques](techniques.md) | Layout, alignment, compression, and limits. |
| [Test data](test-data.md) | The streams checked into this repository. |
| [Publishing](publishing.md) | Packagist registration and the release workflow. |
