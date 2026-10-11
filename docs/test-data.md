# Test data

`testdata/golden/` holds IPC bytes written by PyArrow 25. The PHP tests decode each file. The checked-in bytes are the PyArrow bytes. They are not rewritten by this library. The test runner does not import PyArrow.

| File | What it holds |
| --- | --- |
| `pa-basic.arrows` | `int32`, `utf8`, `bool`, and `float64`, including a null and a NaN. |
| `pa-list.arrows` | `list<int32>` with a null list and an empty list. |
| `pa-struct.arrows` | `struct<x: int32, y: utf8>` with a null struct. |
| `pa-dict.arrows` | Dictionary-encoded `utf8` with `int32` indexes and a null index. |
| `pa-file.arrow` | The IPC file format for a nullable `int32` column, including the footer. |
| `pa-tensor.arrows` | A 2×3 `int32` dense tensor message. |
| `pa-lz4.arrows` | Two hundred `int8` sevens with an LZ4 frame body. |

`testdata/schema/message.json` is the JSON Schema document the code generator reads. `tests/generated/Message.php` is the checked-in output. `bash scripts/check-generated.sh` fails if those two drift apart.

The test runner also builds, in memory, a batch for each logical type the writer supports: null, signed and unsigned integers, float16/32/64, decimal32/128/256, date, time, timestamp, duration, month-day-nano interval, binary and large binary, utf8 and large utf8, fixed-size binary, list, large list, list view, large list view, fixed-size list, struct, map, dense union, sparse union, dictionary, run-end encoding, utf8 view, and binary view. It round-trips an LZ4 body and a Zstd RLE body, an IPC file footer, a V4 stream, a big-endian fixed-width buffer, and dense, COO, CSX, and CSF tensors.

PyArrow is an oracle for local checks. Continuous integration does not install it.
