#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf temp/generated
mkdir -p temp/generated
php bin/gld-arrowgen-php --schema testdata/schema/message.json --out temp/generated
diff -u tests/generated/Message.php temp/generated/Message.php
