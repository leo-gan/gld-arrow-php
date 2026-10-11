# Examples

## One integer column

`examples/encode_batch.php` builds a nullable `int32` column and writes an IPC stream.

```bash
php examples/encode_batch.php
```

The program prints the stream length. The first message is the schema. The second message is the record batch. Eight `0xFF` bytes and a zero metadata length end the stream.

## A generated row

`tests/generated/Message.php` comes from `testdata/schema/message.json`. `id` is an `int64` column and `name` is a `utf8` column. Both are required, so the properties are non-nullable.

```php
use Gld\Arrow\Generated\Message;

$row = new Message(7, 'a');
$raw = $row->encode();
$back = Message::decode($raw);
```

`encodedLength()` is `strlen($row->encode())`.

## Several columns

```php
use Gld\Arrow\Arrow;
use Gld\Arrow\Runtime\TableBuilder;
use Gld\Arrow\Runtime\Values;

$table = (new TableBuilder())
    ->int32('a', [1, null, 3])
    ->utf8('s', ['x', null, 'yz'])
    ->listInt32('l', [[1, 2], null, []])
    ->build();
$bytes = Arrow::writeStream($table);
$back = Arrow::readStream($bytes);
$values = Values::column($back, $back->batches[0]->cols[0]);
```

`Arrow::writeFile` and `Arrow::readFile` use the IPC file format. `Arrow::writeTensors` writes dense and sparse tensor messages.
