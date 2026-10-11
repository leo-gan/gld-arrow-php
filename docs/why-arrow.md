# Why Arrow

Apache Arrow stores a column of values in contiguous buffers. A program that sums one column reads that column's buffers and does not walk the other columns. The same physical layout is what an IPC stream and an IPC file put on the wire.

A record batch is one slice of a table. Every column in the batch has the same number of rows. Nested types such as list, struct, map, and union are still columns: the parent stores offsets or type ids, and the children are arrays of their own.

## What this library covers

The implementation follows the Arrow format documentation for the IPC layout used by Arrow 25.

| Surface | Role |
| --- | --- |
| Columnar arrays | Validity bitmaps, offsets, and value buffers for the logical types. |
| IPC stream | Schema, dictionary batches, and record batches, then an end-of-stream mark. |
| IPC file | The same stream, plus the `ARROW1` magic and a footer of block offsets. |
| Tensor and sparse tensor | One message whose body is the tensor buffer, or the index buffers plus values for COO, CSX, and CSF. |
| Flight | Protocol Buffer messages for handshake, DoGet, and DoPut, and an in-memory call loop. |

The writer emits little-endian metadata version V5. Buffers are aligned to 8 bytes. Two encodes of the same batch with the same options produce the same bytes. PyArrow can read those bytes when it is installed locally. The library itself does not import PyArrow.

Body compression uses an LZ4 frame or a Zstd frame, both implemented in PHP. The in-memory arrays stay uncompressed. The codec is applied when the IPC body is packed. A buffer that does not shrink is stored with an uncompressed-length prefix of `-1`.
