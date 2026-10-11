# Design

gld-arrow-php is a standalone PHP 8.3 library. It implements the Apache Arrow
columnar format used by Arrow 25 IPC: little-endian buffers, 8-byte alignment,
metadata versions V4 and V5 on read, and V5 on write. PyArrow is a test oracle.
The library does not load `ext-arrow` and does not call libarrow.

## Decisions

| Topic | Choice |
| --- | --- |
| Surfaces | Columnar arrays, IPC stream, IPC file, dense tensor, sparse COO, CSX, and CSF tensors, and Flight handshake / DoGet / DoPut. |
| Types | The logical types listed in the README, including views, list views, decimals, unions, dictionaries, and run-end encoding. Extension types stay on their storage type; metadata keys are preserved. |
| Compression | LZ4 frame with a real match encoder, and Zstd frames that use raw blocks and RLE blocks. Both are implemented in PHP. |
| API | `Columnar` arrays, `TableBuilder`, `Values`, and `gld-arrowgen-php`. |
| Schemas | Arrow integration JSON (`fields`) and the JSON Schema subset used by the other gld libraries (`type`, `properties`, `required`, `items`, `title`, `x-arrow-type`). |
| Bytes | Logical values round-trip. Two encodes with the same options match. Padding may differ from PyArrow. |
| IPC metadata | Hand-written FlatBuffers for the Arrow tables only. Field ids match Schema.fbs, Message.fbs, File.fbs, Tensor.fbs, and SparseTensor.fbs. |
| Endianness | The public writer emits little-endian. The reader accepts a big-endian schema and swaps fixed-width value buffers into little-endian memory. |
| Metadata version | The writer emits V5 (FlatBuffers enum value 4). The reader accepts V4 (3) and V5 (4). |
| Flight | Protobuf field numbers for Handshake, Ticket, FlightData, and PutResult. The loop is in-process. It does not open a socket and it does not speak HTTP/2. |
| Speed | Schema messages and record-batch buffer vectors are memoized for repeated reads of the same metadata bytes. Uncompressed bodies are referenced in the input string instead of copied buffer by buffer. An unchanged table reuses the finished IPC stream. An unchanged byte string reuses the decoded columnar and returns an independent copy. |

## What is not implemented

- Zstd Huffman literals and FSE sequence blocks. A PyArrow Zstd body that uses those blocks is rejected. Raw and RLE frames, including ones this library writes, round-trip, and Python `zstandard` decompresses them.
- Dictionary `isDelta` batches are stored and the flag is kept, but deltas are not appended onto the previous dictionary. A non-delta dictionary replaces the previous one for that id.
- There is no C Data Interface and no C Device Interface.
- There is no gRPC or HTTP/2 framing. Flight callers pass one protobuf message and receive one protobuf message.
- `uint64` values above `2^63-1` do not fit in a PHP int. The bit pattern is the signed interpretation from `unpack('q')`. Widths through 64 that fit in a PHP int round-trip, including `uint16` 65535 and `uint32` values that fit.

## Limits

| Limit | Value |
| --- | --- |
| Nesting depth | 64 |
| One buffer | 256 MiB |
| One codec frame | 64 MiB |

A deeper tree or a larger slice raises `ArrowException`. The kind strings are
`EOF`, `SYNTAX`, `RANGE`, `UTF8`, `TYPE`, `DEPTH`, `SCHEMA`, `VERSION`,
`COMPRESSION`, and `FLIGHT`.

## Ship path

Version `0.1.0` is the first commit on `main`. The speed work lands through
the implementation pull request. `0.2.0` is the GitHub Release. There is no
conda publish workflow. The required checks are named `Tests` and `Docs build`.
