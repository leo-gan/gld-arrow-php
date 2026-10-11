<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/tests/generated/Message.php';

use Gld\Arrow\Arrow;
use Gld\Arrow\Compress\Frame;
use Gld\Arrow\Compress\Lz4;
use Gld\Arrow\Compress\Xxh;
use Gld\Arrow\Compress\Zstd;
use Gld\Arrow\Flight\Loop;
use Gld\Arrow\Flight\Messages;
use Gld\Arrow\Generated\Message;
use Gld\Arrow\Runtime\ArrowException;
use Gld\Arrow\Runtime\Bin;
use Gld\Arrow\Runtime\Codec;
use Gld\Arrow\Runtime\Columnar;
use Gld\Arrow\Runtime\Field;
use Gld\Arrow\Runtime\Kind;
use Gld\Arrow\Runtime\Layout;
use Gld\Arrow\Runtime\Limits;
use Gld\Arrow\Runtime\Meta;
use Gld\Arrow\Runtime\TableBuilder;
use Gld\Arrow\Runtime\Tensor;
use Gld\Arrow\Runtime\Type;
use Gld\Arrow\Runtime\Values;
use Gld\Arrow\Schema\Load;
use Gld\Arrow\Wire\Ipc;

$failed = 0;
$passed = 0;

function check(bool $ok, string $name): void
{
    global $failed, $passed;
    if ($ok) {
        $passed++;
        echo "ok  $name\n";
        return;
    }
    $failed++;
    echo "FAIL $name\n";
}

function check_throws(string $kind, callable $fn, string $name): void
{
    try {
        $fn();
        check(false, $name . ' (no throw)');
    } catch (ArrowException $e) {
        check($e->kind === $kind, $name . " ($e->kind)");
    } catch (Throwable $e) {
        check(false, $name . ' (' . $e::class . ')');
    }
}

/** @param list<mixed> $expect */
function check_col(string $name, Columnar $c, array $expect): void
{
    $aid = $c->batches[0]->cols[0];
    $got = Values::column($c, $aid);
    check($got == $expect, $name);
}

function roundtrip(TableBuilder $tb): Columnar
{
    $c = $tb->build();
    $a = Arrow::writeStream($c);
    $b = Arrow::writeStream($c);
    if ($a !== $b) {
        throw new RuntimeException('encode not stable');
    }
    return Arrow::readStream($a);
}

function one(string $name, callable $fill, array $expect): void
{
    try {
        $tb = new TableBuilder();
        $fill($tb);
        check_col($name, roundtrip($tb), $expect);
    } catch (Throwable $e) {
        check(false, $name . ' ' . $e->getMessage());
    }
}

one('nulls', function (TableBuilder $tb): void {
    $id = $tb->addField(Field::named('n', Type::T_NULL, true));
    $tb->column($tb->addArray($id, 2, 2, []));
}, [null, null]);

one('int32 nulls', function (TableBuilder $tb): void {
    $tb->int32('a', [1, null, 3]);
}, [1, null, 3]);

one('ints', function (TableBuilder $tb): void {
    $tb->ints('i8', [-2, 127], 8, true, false);
}, [-2, 127]);

one('uint16', function (TableBuilder $tb): void {
    $tb->ints('u16', [1, 65535], 16, false, false);
}, [1, 65535]);

one('int64', fn (TableBuilder $tb) => $tb->int64('i64', [5, null]), [5, null]);

one('bool', fn (TableBuilder $tb) => $tb->bools('b', [true, null, false]), [true, null, false]);

one('utf8', fn (TableBuilder $tb) => $tb->utf8('s', ['x', null, 'yz']), ['x', null, 'yz']);

one('float64', fn (TableBuilder $tb) => $tb->float64('f', [1.5, null, 0.0]), [1.5, null, 0.0]);

one('list', fn (TableBuilder $tb) => $tb->listInt32('l', [[1, 2], null, []]), [[1, 2], null, []]);

one('struct', function (TableBuilder $tb): void {
    $tb->structIntUtf8('st', [['x' => 1, 'y' => 'a'], null]);
}, [['x' => 1, 'y' => 'a'], null]);

one('dict', function (TableBuilder $tb): void {
    $tb->dictUtf8('d', [0, 1, 0, null], ['a', 'bb']);
}, ['a', 'bb', 'a', null]);

one('f32', function (TableBuilder $tb): void {
    $f = Field::named('f32', Type::T_FLOAT, true);
    $f->unit = 1;
    $id = $tb->addField($f);
    $tb->column($tb->addArray($id, 2, 1, [Layout::validity([false, true]), pack('g', 1.5) . pack('g', 0)]));
}, [1.5, null]);

one('f16', function (TableBuilder $tb): void {
    $f = Field::named('f16', Type::T_FLOAT, false);
    $f->unit = 0;
    $id = $tb->addField($f);
    $tb->column($tb->addArray($id, 2, 0, ['', pack('v', 0x3C00) . pack('v', 0xC000)]));
}, [0x3C00, 0xC000]);

one('decimal128', function (TableBuilder $tb): void {
    $f = Field::named('dec', Type::T_DECIMAL, true);
    $f->precision = 10;
    $f->scale = 2;
    $f->bitWidth = 128;
    $id = $tb->addField($f);
    $tb->column($tb->addArray($id, 2, 1, [
        Layout::validity([false, true]),
        Layout::signExtend(150, 16) . Layout::signExtend(0, 16),
    ]));
}, [Layout::signExtend(150, 16), null]);

one('decimal32', function (TableBuilder $tb): void {
    $f = Field::named('d32', Type::T_DECIMAL, false);
    $f->precision = 5;
    $f->scale = 2;
    $f->bitWidth = 32;
    $id = $tb->addField($f);
    $tb->column($tb->addArray($id, 1, 0, ['', Layout::signExtend(250, 4)]));
}, [Layout::signExtend(250, 4)]);

one('decimal256', function (TableBuilder $tb): void {
    $f = Field::named('d256', Type::T_DECIMAL, false);
    $f->precision = 10;
    $f->scale = 2;
    $f->bitWidth = 256;
    $id = $tb->addField($f);
    $tb->column($tb->addArray($id, 1, 0, ['', Layout::signExtend(-3, 32)]));
}, [Layout::signExtend(-3, 32)]);

one('date', function (TableBuilder $tb): void {
    $f = Field::named('date', Type::T_DATE, true);
    $f->unit = 0;
    $id = $tb->addField($f);
    $tb->column($tb->addArray($id, 1, 0, ['', pack('V', 10)]));
}, [10]);

one('time', function (TableBuilder $tb): void {
    $f = Field::named('time', Type::T_TIME, false);
    $f->unit = 2;
    $f->bitWidth = 64;
    $id = $tb->addField($f);
    $tb->column($tb->addArray($id, 1, 0, ['', pack('q', 15)]));
}, [15]);

one('timestamp', function (TableBuilder $tb): void {
    $f = Field::named('ts', Type::T_TIMESTAMP, true);
    $f->unit = 2;
    $f->timezone = 'UTC';
    $id = $tb->addField($f);
    $tb->column($tb->addArray($id, 1, 0, ['', pack('q', 20)]));
}, [20]);

one('duration', function (TableBuilder $tb): void {
    $f = Field::named('dur', Type::T_DURATION, true);
    $f->unit = 1;
    $id = $tb->addField($f);
    $tb->column($tb->addArray($id, 1, 0, ['', pack('q', 5)]));
}, [5]);

one('interval', function (TableBuilder $tb): void {
    $f = Field::named('iv', Type::T_INTERVAL, false);
    $f->unit = 2;
    $id = $tb->addField($f);
    $tb->column($tb->addArray($id, 1, 0, ['', pack('V', 1) . pack('V', 2) . pack('q', 3)]));
}, [[1, 2, 3]]);

one('binary', function (TableBuilder $tb): void {
    $f = Field::named('bin', Type::T_BINARY, true);
    $id = $tb->addField($f);
    $tb->column($tb->addArray($id, 2, 1, [Layout::validity([false, true]), pack('V*', 0, 2, 2), 'ab']));
}, ['ab', null]);

one('large binary', function (TableBuilder $tb): void {
    $f = Field::named('lbin', Type::T_LARGE_BINARY, true);
    $id = $tb->addField($f);
    $tb->column($tb->addArray($id, 1, 0, ['', pack('q', 0) . pack('q', 2), 'zz']));
}, ['zz']);

one('large utf8', function (TableBuilder $tb): void {
    $f = Field::named('ls', Type::T_LARGE_UTF8, true);
    $id = $tb->addField($f);
    $tb->column($tb->addArray($id, 2, 1, [Layout::validity([false, true]), pack('q', 0) . pack('q', 1) . pack('q', 1), 'q']));
}, ['q', null]);

one('fixed binary', function (TableBuilder $tb): void {
    $f = Field::named('fb', Type::T_FIXED_BINARY, false);
    $f->fixedSize = 3;
    $id = $tb->addField($f);
    $tb->column($tb->addArray($id, 2, 0, ['', 'abcdef']));
}, ['abc', 'def']);

one('fixed list', function (TableBuilder $tb): void {
    $cid = $tb->addField(Field::int('item', 32, true, true));
    $ca = $tb->addArray($cid, 4, 0, ['', Layout::packInts([1, 2, 3, 4], 4)]);
    $fl = Field::nested('fl', Type::T_FIXED_LIST, [$cid], true);
    $fl->fixedSize = 2;
    $id = $tb->addField($fl);
    $tb->column($tb->addArray($id, 2, 0, [''], [$ca]));
}, [[1, 2], [3, 4]]);

one('large list', function (TableBuilder $tb): void {
    $cid = $tb->addField(Field::int('item', 32, true, true));
    $ca = $tb->addArray($cid, 1, 0, ['', pack('V', 7)]);
    $id = $tb->addField(Field::nested('ll', Type::T_LARGE_LIST, [$cid], true));
    $tb->column($tb->addArray($id, 2, 1, [Layout::validity([false, true]), pack('q', 0) . pack('q', 1) . pack('q', 1)], [$ca]));
}, [[7], null]);

one('list view', function (TableBuilder $tb): void {
    $cid = $tb->addField(Field::int('item', 32, true, true));
    $ca = $tb->addArray($cid, 2, 0, ['', Layout::packInts([1, 2], 4)]);
    $id = $tb->addField(Field::nested('lv', Type::T_LIST_VIEW, [$cid], true));
    $tb->column($tb->addArray($id, 2, 0, ['', pack('V*', 0, 2), pack('V*', 2, 0)], [$ca]));
}, [[1, 2], []]);

one('large list view', function (TableBuilder $tb): void {
    $cid = $tb->addField(Field::int('item', 32, true, true));
    $ca = $tb->addArray($cid, 1, 0, ['', pack('V', 9)]);
    $id = $tb->addField(Field::nested('llv', Type::T_LARGE_LIST_VIEW, [$cid], false));
    $tb->column($tb->addArray($id, 1, 0, ['', pack('q', 0), pack('q', 1)], [$ca]));
}, [[9]]);

one('map', function (TableBuilder $tb): void {
    $kid = $tb->addField(Field::utf8('key', false));
    $ka = $tb->addArray($kid, 1, 0, ['', pack('V*', 0, 1), 'k']);
    $vid = $tb->addField(Field::int('value', 32, true, true));
    $va = $tb->addArray($vid, 1, 0, ['', pack('V', 1)]);
    $eid = $tb->addField(Field::nested('entries', Type::T_STRUCT, [$kid, $vid], false));
    $ea = $tb->addArray($eid, 1, 0, [''], [$ka, $va]);
    $map = Field::nested('m', Type::T_MAP, [$eid], true);
    $map->keysSorted = true;
    $id = $tb->addField($map);
    $tb->column($tb->addArray($id, 2, 1, [Layout::validity([false, true]), pack('V*', 0, 1, 1)], [$ea]));
}, [[['k', 1]], null]);

one('dense union', function (TableBuilder $tb): void {
    $iId = $tb->addField(Field::int('i', 32, true, true));
    $ia = $tb->addArray($iId, 1, 0, ['', pack('V', 5)]);
    $sId = $tb->addField(Field::utf8('s', true));
    $sa = $tb->addArray($sId, 1, 0, ['', pack('V*', 0, 2), 'ab']);
    $uf = Field::nested('u', Type::T_UNION, [$iId, $sId], false);
    $uf->unionMode = 1;
    $uf->typeIds = [0, 1];
    $id = $tb->addField($uf);
    $tb->column($tb->addArray($id, 2, 0, ["\x00\x01", pack('V*', 0, 0)], [$ia, $sa]));
}, [['id' => 0, 'value' => 5], ['id' => 1, 'value' => 'ab']]);

one('sparse union', function (TableBuilder $tb): void {
    $iId = $tb->addField(Field::int('i', 32, true, true));
    $ia = $tb->addArray($iId, 2, 1, [Layout::validity([false, true]), pack('V*', 9, 0)]);
    $sId = $tb->addField(Field::utf8('s', true));
    $sa = $tb->addArray($sId, 2, 1, [Layout::validity([true, false]), pack('V*', 0, 0, 1), 'z']);
    $uf = Field::nested('su', Type::T_UNION, [$iId, $sId], false);
    $uf->unionMode = 0;
    $uf->typeIds = [0, 1];
    $id = $tb->addField($uf);
    $tb->column($tb->addArray($id, 2, 0, ["\x00\x01"], [$ia, $sa]));
}, [['id' => 0, 'value' => 9], ['id' => 1, 'value' => 'z']]);

one('run-end', function (TableBuilder $tb): void {
    $rid = $tb->addField(Field::int('run_ends', 32, true, false));
    $ra = $tb->addArray($rid, 2, 0, ['', pack('V*', 2, 5)]);
    $vid = $tb->addField(Field::int('values', 32, true, true));
    $va = $tb->addArray($vid, 2, 0, ['', pack('V*', 1, 2)]);
    $id = $tb->addField(Field::nested('r', Type::T_RUN_END, [$rid, $vid], true));
    $tb->column($tb->addArray($id, 5, 0, [], [$ra, $va]));
}, [1, 1, 2, 2, 2]);

one('utf8 view', function (TableBuilder $tb): void {
    $short = pack('V', 2) . "hi" . str_repeat("\0", 10);
    $long = pack('V', 26) . 'abcd' . pack('V', 0) . pack('V', 0);
    $id = $tb->addField(Field::named('v', Type::T_UTF8_VIEW, true));
    $tb->column($tb->addArray($id, 3, 1, [
        Layout::validity([false, false, true]),
        $short . $long . str_repeat("\0", 16),
        'abcdefghijklmnopqrstuvwxyz',
    ]));
}, ['hi', 'abcdefghijklmnopqrstuvwxyz', null]);

one('binary view', function (TableBuilder $tb): void {
    $inline = pack('V', 3) . 'xyz' . str_repeat("\0", 9);
    $id = $tb->addField(Field::named('bv', Type::T_BINARY_VIEW, false));
    $tb->column($tb->addArray($id, 1, 0, ['', $inline]));
}, ['xyz']);

$built = (new TableBuilder())
    ->int32('a', [1, null, 3])
    ->utf8('s', ['x', null, 'yz'])
    ->bools('b', [true, null, false])
    ->build();
$stream = Arrow::writeStream($built);
check($stream === Arrow::writeStream($built), 'stream bytes stable');
$file = Arrow::writeFile($built);
check($file === Arrow::writeFile($built), 'file bytes stable');
check(str_starts_with($file, "ARROW1\0\0") && str_ends_with($file, 'ARROW1'), 'file magic');
$readFile = Arrow::readFile($file);
check($readFile->footer !== null && count($readFile->footer->recordBatches) === 1, 'footer block');
check($readFile->footer->version === Meta::V5, 'footer v5');
check(Values::column($readFile, $readFile->batches[0]->cols[0]) == [1, null, 3], 'file values');
$block = $readFile->footer->recordBatches[0];
check($block->offset >= 8 && $block->metaLength > 8 && $block->bodyLength > 0, 'footer offsets');

$v4 = Ipc::writeStream($built, Meta::V4);
check(Ipc::readStream($v4)->batches[0]->length === 3, 'read metadata v4');

$be = new Columnar();
$be->endian = 1;
$fid = $be->addField(Field::int('a', 32, true, false));
$be->addTop($fid);
$arr = new Gld\Arrow\Runtime\Arr();
$arr->field = $fid;
$arr->length = 1;
$arr->nullCount = 0;
$arr->bufs = [$be->addBuf(''), $be->addBuf(pack('N', 0x01020304))];
$be->addBatch(1, [$be->addArray($arr)]);
$back = Ipc::readStream(Ipc::writeStream($be));
check(bin2hex($back->buf($back->arrays[0]->bufs[1])) === '04030201', 'big-endian swap');

$z = new TableBuilder();
$z->codec(Codec::ZSTD);
$id = $z->addField(Field::int('z', 8, false, false));
$z->column($z->addArray($id, 300, 0, ['', str_repeat("\x07", 300)]));
$zbytes = Arrow::writeStream($z->build());
check(str_contains($zbytes, "\x28\xb5\x2f\xfd"), 'zstd frame in body');
$zback = Arrow::readStream($zbytes);
check($zback->batches[0]->codec === Codec::ZSTD, 'zstd codec id');
check($zback->buf($zback->arrays[$zback->batches[0]->cols[0]]->bufs[1]) === str_repeat("\x07", 300), 'zstd values');

$lz = new TableBuilder();
$lz->codec(Codec::LZ4);
$id = $lz->addField(Field::int('a', 32, true, false));
$packed = Layout::packInts(array_fill(0, 200, 7), 4);
$lz->column($lz->addArray($id, 200, 0, ['', $packed]));
$lbytes = Arrow::writeStream($lz->build());
check(str_contains($lbytes, "\x04\x22\x4d\x18"), 'lz4 frame in body');
$lback = Arrow::readStream($lbytes);
check($lback->batches[0]->codec === Codec::LZ4, 'lz4 codec id');
check($lback->buf($lback->arrays[$lback->batches[0]->cols[0]]->bufs[1]) === $packed, 'lz4 values');

$rep = str_repeat('abc', 1000);
check(Lz4::decompress(Lz4::compress($rep)) === $rep, 'lz4 frame round-trip');
check(strlen(Lz4::compress($rep)) < strlen($rep), 'lz4 shrinks');
check(Zstd::decompress(Zstd::compress(str_repeat('Z', 5000))) === str_repeat('Z', 5000), 'zstd rle round-trip');
check(strlen(Zstd::compress(str_repeat('Z', 5000))) < 5000, 'zstd rle shrinks');
check(Zstd::decompress(Zstd::compress('hello')) === 'hello', 'zstd raw round-trip');
$checksummed = Zstd::compress('hello checksum');
$checksummed[4] = chr(ord($checksummed[4]) | 0x04);
$checksummed .= pack('V', Xxh::xxh64Low32('hello checksum'));
check(Zstd::decompress($checksummed) === 'hello checksum', 'zstd content checksum');
$badChecksum = $checksummed;
$badChecksum[strlen($badChecksum) - 1] = chr(ord($badChecksum[strlen($badChecksum) - 1]) ^ 1);
check_throws(Kind::COMPRESSION, static fn () => Zstd::decompress($badChecksum), 'zstd bad checksum');
$skip = pack('V', 0x184D2A50) . pack('V', 4) . 'skip' . Zstd::compress('abc');
check(Zstd::decompress($skip) === 'abc', 'zstd skippable frame');
check(hexdec(hash('xxh32', '123456789')) === Xxh::xxh32('123456789'), 'xxh32');
check(hash('xxh64', '123456789') === sprintf('%08x%08x', Xxh::xxh64('123456789')[1], Xxh::xxh64('123456789')[0]), 'xxh64');

$c = new Columnar();
$fid = $c->addField(Field::int('', 32, true, false));
$t = new Tensor();
$t->field = $fid;
$t->shape = [2, 3];
$t->strides = [12, 4];
$raw = '';
for ($i = 0; $i < 6; $i++) {
    $raw .= pack('V', $i);
}
$t->dataBuf = $c->addBuf($raw);
$c->tensors[] = $t;
$tensor = Arrow::writeTensors($c);
check($tensor === Arrow::writeTensors($c), 'tensor bytes stable');
$td = Arrow::readTensors($tensor);
check($td->tensors[0]->shape === [2, 3] && $td->tensors[0]->strides === [12, 4], 'dense tensor shape');
check($td->buf($td->tensors[0]->dataBuf) === $raw, 'dense tensor body');

$c = new Columnar();
$fid = $c->addField(Field::int('', 32, true, false));
$coo = new Tensor();
$coo->field = $fid;
$coo->shape = [2, 2];
$coo->sparseKind = 1;
$coo->nz = 2;
$coo->cooCanonical = true;
$coo->indexWidth = 32;
$coo->indexSigned = true;
$coo->dataBuf = $c->addBuf(pack('V*', 1, 2));
$coo->indexBufs[] = $c->addBuf(pack('V*', 0, 1, 1, 0));
$c->tensors[] = $coo;
$fid = $c->addField(Field::int('', 32, true, false));
$csx = new Tensor();
$csx->field = $fid;
$csx->shape = [2, 2];
$csx->sparseKind = 2;
$csx->nz = 2;
$csx->csxAxis = 1;
$csx->indexWidth = 32;
$csx->indexSigned = false;
$csx->dataBuf = $c->addBuf(pack('V*', 4, 5));
$csx->indexBufs[] = $c->addBuf(pack('V*', 0, 1, 2));
$csx->indexBufs[] = $c->addBuf(pack('V*', 1, 0));
$c->tensors[] = $csx;
$fid = $c->addField(Field::int('', 32, true, false));
$csf = new Tensor();
$csf->field = $fid;
$csf->shape = [2, 2];
$csf->sparseKind = 3;
$csf->nz = 2;
$csf->indexWidth = 32;
$csf->indexSigned = true;
$csf->axisOrder = [0, 1];
$csf->dataBuf = $c->addBuf(pack('V*', 8, 9));
$csf->indexBufs[] = $c->addBuf(pack('V*', 0, 2));
$csf->indexBufs[] = $c->addBuf(pack('V*', 0, 1));
$c->tensors[] = $csf;
$sd = Arrow::readTensors(Arrow::writeTensors($c));
check($sd->tensors[0]->sparseKind === 1 && $sd->tensors[0]->cooCanonical && $sd->buf($sd->tensors[0]->dataBuf) === pack('V*', 1, 2), 'coo tensor');
check($sd->tensors[1]->sparseKind === 2 && $sd->tensors[1]->csxAxis === 1 && count($sd->tensors[1]->indexBufs) === 2, 'csx tensor');
check($sd->tensors[2]->sparseKind === 3 && $sd->tensors[2]->axisOrder === [0, 1] && $sd->buf($sd->tensors[2]->dataBuf) === pack('V*', 8, 9), 'csf tensor');

$loop = new Loop();
$hs = Messages::readHandshake($loop->call('Handshake', Messages::handshake(3, 'tok')));
check($hs['version'] === 3 && $hs['payload'] === 'tok', 'flight handshake');
$put = Messages::readPutResult($loop->call('DoPut', Messages::flightData('SCH', 'BODY', 'meta')));
check($put === 'meta', 'flight do_put');
$get = Messages::readFlightData($loop->call('DoGet', Messages::ticket('t1')));
check($get['header'] === 'SCH' && $get['body'] === 'BODY' && $get['meta'] === 'ok', 'flight do_get');
check_throws(Kind::FLIGHT, static fn () => $loop->call('DoGet', Messages::ticket('no')), 'flight bad ticket');

$row = new Message(7, 'a');
$decoded = Message::decode($row->encode());
check($decoded->id === 7 && $decoded->name === 'a' && $row->encodedLength() === strlen($row->encode()), 'generated message');

$loaded = Load::json((string) file_get_contents(dirname(__DIR__) . '/testdata/schema/message.json'));
check(count($loaded->top) === 1 && $loaded->fields[$loaded->top[0]]->type === Type::T_STRUCT, 'json schema object');
check(count($loaded->fields[$loaded->top[0]]->children) === 2, 'json schema fields');
$arrowJson = '{"fields":[{"name":"a","nullable":false,"type":{"name":"int","bitWidth":16,"isSigned":false},"metadata":[{"key":"k","value":"v"}]}]}';
$asc = Load::json($arrowJson);
$af = $asc->fields[$asc->top[0]];
check($af->name === 'a' && $af->bitWidth === 16 && $af->signed === false && $af->nullable === false, 'arrow schema json');
check($af->metadata === [['k', 'v']], 'field metadata json');

$root = dirname(__DIR__) . '/testdata/golden';
$basicRaw = (string) file_get_contents($root . '/pa-basic.arrows');
$basic = Arrow::readStream($basicRaw);
$basicAgain = Arrow::readStream(Arrow::writeStream($basic));
check(Values::column($basicAgain, $basicAgain->batches[0]->cols[0]) == [1, null, 3], 're-encode pyarrow basic');
check(Values::column($basic, $basic->batches[0]->cols[0]) == [1, null, 3], 'pyarrow basic int');
check(Values::column($basic, $basic->batches[0]->cols[1]) == ['x', null, 'yz'], 'pyarrow basic utf8');
check(Values::column($basic, $basic->batches[0]->cols[2]) == [true, null, false], 'pyarrow basic bool');
$floats = Values::column($basic, $basic->batches[0]->cols[3]);
check($floats[0] === 1.5 && $floats[1] === null && is_float($floats[2]) && is_nan($floats[2]), 'pyarrow basic float');
$list = Arrow::readStream((string) file_get_contents($root . '/pa-list.arrows'));
check(Values::column($list, $list->batches[0]->cols[0]) == [[1, 2], null, []], 'pyarrow list');
$struct = Arrow::readStream((string) file_get_contents($root . '/pa-struct.arrows'));
check(Values::column($struct, $struct->batches[0]->cols[0]) == [['x' => 1, 'y' => 'a'], null], 'pyarrow struct');
$dict = Arrow::readStream((string) file_get_contents($root . '/pa-dict.arrows'));
check(Values::column($dict, $dict->batches[0]->cols[0]) == ['a', 'bb', 'a', null], 'pyarrow dictionary');
$pfile = Arrow::readFile((string) file_get_contents($root . '/pa-file.arrow'));
check(Values::column($pfile, $pfile->batches[0]->cols[0]) == [1, null, 3] && count($pfile->footer->recordBatches) === 1, 'pyarrow file');
$pt = Arrow::readTensors((string) file_get_contents($root . '/pa-tensor.arrows'));
check($pt->tensors[0]->shape === [2, 3] && $pt->buf($pt->tensors[0]->dataBuf) === $raw, 'pyarrow tensor');
$plz = Arrow::readStream((string) file_get_contents($root . '/pa-lz4.arrows'));
check($plz->batches[0]->codec === Codec::LZ4 && $plz->buf($plz->arrays[$plz->batches[0]->cols[0]]->bufs[1]) === str_repeat("\x07", 200), 'pyarrow lz4 body');

check_throws(Kind::SYNTAX, static fn () => Arrow::readStream('not-arrow'), 'bad continuation');
check_throws(Kind::SYNTAX, static fn () => Arrow::readFile('short'), 'bad file magic');
check_throws(Kind::VERSION, static fn () => Ipc::writeStream($built, 1), 'writer rejects v1');
check_throws(Kind::UTF8, static fn () => Bin::utf8("\xff"), 'utf8');
check_throws(Kind::RANGE, static fn () => Bin::checkBuffer(Limits::BUFFER + 1), 'buffer limit');
check_throws(Kind::DEPTH, static function (): void {
    $c = new Columnar();
    $field = $c->addField(Field::int('item', 32, true, true));
    $arrId = $c->addArray((function () use ($c, $field) {
        $a = new Gld\Arrow\Runtime\Arr();
        $a->field = $field;
        $a->length = 0;
        $a->bufs = [$c->addBuf(''), $c->addBuf(pack('V', 0))];
        return $a;
    })());
    for ($d = 0; $d < 70; $d++) {
        $parent = $c->addField(Field::nested('L' . $d, Type::T_LIST, [$field], true));
        $a = new Gld\Arrow\Runtime\Arr();
        $a->field = $parent;
        $a->length = 0;
        $a->bufs = [$c->addBuf(''), $c->addBuf(pack('V', 0))];
        $a->children = [$arrId];
        $arrId = $c->addArray($a);
        $field = $parent;
    }
    $c->addTop($field);
    $c->addBatch(0, [$arrId]);
    Arrow::writeStream($c);
}, 'nesting depth');

$meta = (new TableBuilder())->meta('schema', 'yes')->int32('a', [1])->build();
$meta->fields[$meta->top[0]]->metadata = [['k', 'v']];
$mb = Arrow::readStream(Arrow::writeStream($meta));
check($mb->metadata === [['schema', 'yes']] && $mb->fields[$mb->top[0]]->metadata === [['k', 'v']], 'metadata round-trip');

check(Frame::decompress(Codec::LZ4, Frame::compress(Codec::LZ4, 'xyz')) === 'xyz', 'frame lz4');
check(Frame::decompress(Codec::ZSTD, Frame::compress(Codec::ZSTD, '')) === '', 'frame zstd empty');

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
