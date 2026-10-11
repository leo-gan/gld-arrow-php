# Techniques

## IPC messages

Each message starts with the continuation `0xFFFFFFFF` and a little-endian metadata length. The metadata is a FlatBuffer `Message` padded to 8 bytes. The body length in that message includes the padding that follows the body, so the next message stays aligned.

An IPC file starts with the six bytes `ARROW1` and two zero bytes. The embedded stream, including the end-of-stream mark, follows. The footer is a FlatBuffer. A little-endian footer length and a second `ARROW1` finish the file. Each footer block stores the file offset of a dictionary or record-batch message, the metadata length including the 8-byte prefix, and the body length.

The FlatBuffer tables are written by `src/Runtime/FBBuilder.php`. Field identifiers match the Arrow 25 `.fbs` files. There is no dependency on another FlatBuffers package. Metadata version V5 is the enum value 4. V4 is the enum value 3. An omitted compression codec byte means LZ4, which is the FlatBuffers default of 0. Zstd is written as 1.

## Arrays

Integer, floating-point, decimal, and temporal values are little-endian in memory, even when a file declared big-endian. The reader swaps the fixed-width value buffer once while it copies that buffer. Validity bits are packed with the least significant bit first. A null count of zero omits the validity bytes and still records a zero-length buffer.

Dictionary columns store index buffers in the record batch. The dictionary values travel in an earlier `DictionaryBatch`. A later batch with `isDelta` false replaces that dictionary for logical reads. Nested field children are stored on the field so a child struct's own children do not become children of the parent.

Utf8 view and binary view use a 16-byte view per row. Strings of 12 bytes or fewer are inline. Longer strings point at a variadic data buffer. The variadic count is written even when it is zero.

## Compression and limits

| Codec value | Frame |
| --- | --- |
| 0 | LZ4 frame, magic `04 22 4D 18`. The writer uses independent 64 KiB blocks and a hash-table matcher. |
| 1 | Zstd frame, magic `28 B5 2F FD`. The writer emits raw blocks and RLE blocks. The reader accepts skippable frames and a content checksum. Huffman literals and FSE sequences are rejected. |

A compressed buffer starts with the uncompressed length as a little-endian `int64`. The value `-1` means the following bytes are already uncompressed. Empty buffers stay empty and omit that prefix. If the frame is not smaller than the raw buffer, the writer stores the raw bytes with the `-1` prefix and still records the codec on the record batch.

| Limit | Value |
| --- | --- |
| Nesting depth | 64 |
| One buffer | 256 MiB |
| One codec frame | 64 MiB |

## Flight

`Flight\Messages` encodes Handshake (`protocol_version`, `payload`), Ticket, FlightData (`data_header`, `app_metadata`, `data_body` = field 1000), and PutResult. `Flight\Loop::call` dispatches `Handshake`, `DoGet`, and `DoPut` in memory. DoGet accepts the ticket `t1` and returns the schema and batch previously stored by DoPut.

## Repeated metadata

Decoding the same schema message or the same record-batch metadata twice reuses the parsed FlatBuffer vectors. The cache holds at most 16 entries. Uncompressed buffer bytes are views into the input string when that string is still the columnar byte arena. A later `addBuf` of new bytes copies the arena. Writing the same table again returns the IPC stream from the first encode when the schema, buffer spans, and array layout are unchanged. Reading that same byte string again copies the decoded columnar instead of parsing the FlatBuffers a second time. Changing a field, a buffer, or the byte arena invalidates the stream.
