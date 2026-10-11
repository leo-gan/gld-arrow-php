<?php

declare(strict_types=1);

namespace Gld\Arrow\Schema;

use Gld\Arrow\Runtime\ArrowException;
use Gld\Arrow\Runtime\Columnar;
use Gld\Arrow\Runtime\Field;
use Gld\Arrow\Runtime\Kind;
use Gld\Arrow\Runtime\Type;

final class Load
{
    public static function json(string $raw): Columnar
    {
        $doc = json_decode($raw, true);
        if (!is_array($doc)) {
            throw new ArrowException(Kind::SCHEMA, 0, 'json');
        }
        $c = new Columnar();
        if (isset($doc['fields']) && !isset($doc['properties'])) {
            self::arrowSchema($doc, $c);
        } else {
            $root = self::jsonSchema($doc, $c, self::title($doc), true);
            $c->addTop($root);
        }
        return $c;
    }

    /** @param array<string,mixed> $doc */
    public static function title(array $doc): string
    {
        if (isset($doc['title']) && is_string($doc['title']) && $doc['title'] !== '') {
            return $doc['title'];
        }
        if (isset($doc['metadata']) && is_array($doc['metadata'])) {
            foreach ($doc['metadata'] as $pair) {
                if (is_array($pair) && ($pair['key'] ?? '') === 'title' && is_string($pair['value'] ?? null)) {
                    return $pair['value'];
                }
            }
        }
        return 'Root';
    }

    /** @param array<string,mixed> $doc */
    private static function arrowSchema(array $doc, Columnar $c): void
    {
        if (!is_array($doc['fields'])) {
            throw new ArrowException(Kind::SCHEMA, 0);
        }
        foreach ($doc['fields'] as $field) {
            if (!is_array($field)) {
                throw new ArrowException(Kind::SCHEMA, 0);
            }
            $c->addTop(self::arrowField($field, $c));
        }
        if (isset($doc['metadata']) && is_array($doc['metadata'])) {
            $c->metadata = self::metaList($doc['metadata']);
        }
    }

    /** @param array<string,mixed> $field */
    private static function arrowField(array $field, Columnar $c): int
    {
        $f = new Field();
        if (isset($field['name']) && is_string($field['name'])) {
            $f->name = $field['name'];
        }
        if (array_key_exists('nullable', $field) && $field['nullable'] === false) {
            $f->nullable = false;
        }
        if (!isset($field['type'])) {
            throw new ArrowException(Kind::SCHEMA, 0, 'type');
        }
        self::arrowType($field['type'], $f);
        if (isset($field['dictionary']) && is_array($field['dictionary'])) {
            $f->dictId = (int) ($field['dictionary']['id'] ?? 0);
            if (isset($field['dictionary']['indexType']) && is_array($field['dictionary']['indexType'])) {
                $f->dictIndexWidth = (int) ($field['dictionary']['indexType']['bitWidth'] ?? 32);
                $f->dictIndexSigned = (bool) ($field['dictionary']['indexType']['isSigned'] ?? true);
            }
            $f->dictOrdered = (bool) ($field['dictionary']['isOrdered'] ?? false);
        }
        $id = $c->addField($f);
        $kids = [];
        if (isset($field['children']) && is_array($field['children'])) {
            foreach ($field['children'] as $child) {
                if (!is_array($child)) {
                    throw new ArrowException(Kind::SCHEMA, 0);
                }
                $kids[] = self::arrowField($child, $c);
            }
        }
        $f->children = $kids;
        if (isset($field['metadata']) && is_array($field['metadata'])) {
            $f->metadata = self::metaList($field['metadata']);
        }
        return $id;
    }

    private static function arrowType(mixed $type, Field $f): void
    {
        if (is_string($type)) {
            self::applyName($type, $f);
            return;
        }
        if (!is_array($type) || !isset($type['name']) || !is_string($type['name'])) {
            throw new ArrowException(Kind::SCHEMA, 0, 'type name');
        }
        self::applyName($type['name'], $f);
        if (isset($type['bitWidth'])) {
            $f->bitWidth = (int) $type['bitWidth'];
        }
        if (array_key_exists('isSigned', $type)) {
            $f->signed = (bool) $type['isSigned'];
        }
        if (isset($type['precision']) && is_numeric($type['precision'])) {
            $f->precision = (int) $type['precision'];
        } elseif (isset($type['precision']) && is_string($type['precision'])) {
            $f->unit = match (strtoupper($type['precision'])) {
                'HALF' => 0,
                'SINGLE' => 1,
                default => 2,
            };
        }
        if (isset($type['scale'])) {
            $f->scale = (int) $type['scale'];
        }
        if (isset($type['unit']) && is_string($type['unit'])) {
            $f->unit = self::unitName($type['unit']);
        }
        if (isset($type['timezone']) && is_string($type['timezone'])) {
            $f->timezone = $type['timezone'];
        }
        if (isset($type['byteWidth'])) {
            $f->fixedSize = (int) $type['byteWidth'];
        }
        if (isset($type['listSize'])) {
            $f->fixedSize = (int) $type['listSize'];
        }
        if (isset($type['keysSorted'])) {
            $f->keysSorted = (bool) $type['keysSorted'];
        }
        if (isset($type['mode']) && is_string($type['mode'])) {
            $f->unionMode = strtoupper($type['mode']) === 'DENSE' ? 1 : 0;
        }
        if (isset($type['typeIds']) && is_array($type['typeIds'])) {
            foreach ($type['typeIds'] as $id) {
                $f->typeIds[] = (int) $id;
            }
        }
        if ($f->type === Type::T_DECIMAL && $f->bitWidth === 0) {
            $f->bitWidth = 128;
        }
    }

    private static function applyName(string $name, Field $f): void
    {
        $n = strtolower($name);
        switch ($n) {
            case 'null':
                $f->type = Type::T_NULL;
                return;
            case 'bool':
            case 'boolean':
                $f->type = Type::T_BOOL;
                return;
            case 'int':
            case 'int32':
                $f->type = Type::T_INT;
                if ($f->bitWidth === 0) {
                    $f->bitWidth = $n === 'int32' ? 32 : 32;
                }
                return;
            case 'int8':
            case 'int16':
            case 'int64':
            case 'uint8':
            case 'uint16':
            case 'uint32':
            case 'uint64':
                $f->type = Type::T_INT;
                $f->bitWidth = (int) filter_var($n, FILTER_SANITIZE_NUMBER_INT);
                $f->signed = !str_starts_with($n, 'u');
                return;
            case 'utf8':
            case 'string':
                $f->type = Type::T_UTF8;
                return;
            case 'largeutf8':
            case 'largestring':
                $f->type = Type::T_LARGE_UTF8;
                return;
            case 'binary':
                $f->type = Type::T_BINARY;
                return;
            case 'largebinary':
                $f->type = Type::T_LARGE_BINARY;
                return;
            case 'utf8view':
            case 'stringview':
                $f->type = Type::T_UTF8_VIEW;
                return;
            case 'binaryview':
                $f->type = Type::T_BINARY_VIEW;
                return;
            case 'fixedsizebinary':
                $f->type = Type::T_FIXED_BINARY;
                return;
            case 'floatingpoint':
            case 'float64':
            case 'float':
                $f->type = Type::T_FLOAT;
                if ($n === 'float64' || $n === 'float') {
                    $f->unit = 2;
                }
                return;
            case 'float32':
                $f->type = Type::T_FLOAT;
                $f->unit = 1;
                return;
            case 'float16':
            case 'halffloat':
                $f->type = Type::T_FLOAT;
                $f->unit = 0;
                return;
            case 'decimal':
                $f->type = Type::T_DECIMAL;
                return;
            case 'date':
            case 'date32':
                $f->type = Type::T_DATE;
                $f->unit = 0;
                return;
            case 'date64':
                $f->type = Type::T_DATE;
                $f->unit = 1;
                return;
            case 'time':
                $f->type = Type::T_TIME;
                return;
            case 'timestamp':
                $f->type = Type::T_TIMESTAMP;
                return;
            case 'duration':
                $f->type = Type::T_DURATION;
                return;
            case 'interval':
                $f->type = Type::T_INTERVAL;
                return;
            case 'list':
                $f->type = Type::T_LIST;
                return;
            case 'largelist':
                $f->type = Type::T_LARGE_LIST;
                return;
            case 'listview':
                $f->type = Type::T_LIST_VIEW;
                return;
            case 'largelistview':
                $f->type = Type::T_LARGE_LIST_VIEW;
                return;
            case 'fixedsizelist':
                $f->type = Type::T_FIXED_LIST;
                return;
            case 'struct':
            case 'struct_':
                $f->type = Type::T_STRUCT;
                return;
            case 'map':
                $f->type = Type::T_MAP;
                return;
            case 'union':
                $f->type = Type::T_UNION;
                return;
            case 'runendencoded':
                $f->type = Type::T_RUN_END;
                return;
            default:
                throw new ArrowException(Kind::SCHEMA, 0, $name);
        }
    }

    private static function unitName(string $name): int
    {
        return match (strtoupper($name)) {
            'SECOND', 'YEAR_MONTH', 'DAY' => 0,
            'MILLISECOND', 'DAY_TIME' => 1,
            'MICROSECOND', 'MONTH_DAY_NANO' => 2,
            'NANOSECOND' => 3,
            default => 1,
        };
    }

    /** @param list<mixed> $pairs @return list<array{0:string,1:string}> */
    private static function metaList(array $pairs): array
    {
        $out = [];
        foreach ($pairs as $pair) {
            if (!is_array($pair)) {
                continue;
            }
            $out[] = [(string) ($pair['key'] ?? ''), (string) ($pair['value'] ?? '')];
        }
        return $out;
    }

    /** @param array<string,mixed> $doc */
    private static function jsonSchema(array $doc, Columnar $c, string $name, bool $nullable): int
    {
        if (isset($doc['x-arrow-type']) && is_string($doc['x-arrow-type'])) {
            $f = new Field();
            $f->name = $name;
            $f->nullable = $nullable;
            self::applyName($doc['x-arrow-type'], $f);
            return $c->addField($f);
        }
        $type = $doc['type'] ?? null;
        if (is_array($type) && count($type) === 2 && ($type[0] ?? '') === 'null' && is_string($type[1] ?? null)) {
            return self::namedType($doc, (string) $type[1], $c, $name, true);
        }
        if (is_string($type)) {
            return self::namedType($doc, $type, $c, $name, $nullable);
        }
        if (isset($doc['properties'])) {
            return self::object($doc, $c, $name, $nullable);
        }
        throw new ArrowException(Kind::SCHEMA, 0, 'json schema');
    }

    /** @param array<string,mixed> $doc */
    private static function namedType(array $doc, string $name, Columnar $c, string $fieldName, bool $nullable): int
    {
        if ($name === 'object') {
            return self::object($doc, $c, $fieldName, $nullable);
        }
        if ($name === 'array') {
            if (!isset($doc['items']) || !is_array($doc['items'])) {
                throw new ArrowException(Kind::SCHEMA, 0, 'items');
            }
            $child = self::jsonSchema($doc['items'], $c, $fieldName . 'Item', true);
            $f = Field::nested($fieldName, Type::T_LIST, [$child], $nullable);
            return $c->addField($f);
        }
        $f = new Field();
        $f->name = $fieldName;
        $f->nullable = $nullable;
        switch ($name) {
            case 'integer':
                $f->type = Type::T_INT;
                $f->bitWidth = 64;
                $f->signed = true;
                break;
            case 'number':
                $f->type = Type::T_FLOAT;
                $f->unit = 2;
                break;
            case 'string':
                $f->type = Type::T_UTF8;
                break;
            case 'boolean':
                $f->type = Type::T_BOOL;
                break;
            case 'null':
                $f->type = Type::T_NULL;
                break;
            default:
                throw new ArrowException(Kind::SCHEMA, 0, $name);
        }
        return $c->addField($f);
    }

    /** @param array<string,mixed> $doc */
    private static function object(array $doc, Columnar $c, string $name, bool $nullable): int
    {
        $f = Field::named($name, Type::T_STRUCT, $nullable);
        $id = $c->addField($f);
        $kids = [];
        $props = $doc['properties'] ?? [];
        if (is_array($props)) {
            foreach ($props as $key => $child) {
                if (!is_string($key) || !is_array($child)) {
                    throw new ArrowException(Kind::SCHEMA, 0);
                }
                $req = self::required($doc, $key);
                $kids[] = self::jsonSchema($child, $c, $key, !$req);
            }
        }
        $f->children = $kids;
        return $id;
    }

    /** @param array<string,mixed> $doc */
    private static function required(array $doc, string $name): bool
    {
        $req = $doc['required'] ?? [];
        return is_array($req) && in_array($name, $req, true);
    }
}
