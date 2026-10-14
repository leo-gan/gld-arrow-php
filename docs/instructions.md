# Instructions

The package name is `leo-gan/gld-arrow-php`. The PHP namespace is `Gld\Arrow`. The code generator command is `gld-arrowgen-php`.

Requires PHP 8.3 or newer. `ext-arrow` is not used.

## Install

```bash
composer require leo-gan/gld-arrow-php
```

## Develop from this repository

```bash
git clone https://github.com/leo-gan/gld-arrow-php.git
cd gld-arrow-php
php tests/run.php
bash scripts/check-generated.sh
php examples/encode_batch.php
php benches/bench.php
```

`php tests/run.php` does not install Composer packages. The runner registers `src/` and `tests/generated/` itself. `bash scripts/check-generated.sh` regenerates `tests/generated/Message.php` and diffs it.

## Generate a class

`testdata/schema/message.json` is a JSON Schema object. The generator also accepts an Arrow integration schema whose top-level key is `fields`. A schema metadata entry named `title`, or a JSON Schema `title`, names the generated class. Without that entry the name is `Root`.

```bash
php bin/gld-arrowgen-php --schema testdata/schema/message.json --out tests/generated
```

The class `Message` has `encodedLength`, `encode`, and `decode`. `encode` returns one IPC stream that holds a single row.

## Composer

`composer.json` maps `Gld\Arrow\` to `src/` with PSR-4. The committed test class lives in `tests/generated/` under `Gld\Arrow\Generated\`.
