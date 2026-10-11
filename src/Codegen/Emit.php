<?php

declare(strict_types=1);

namespace Gld\Arrow\Codegen;

use Gld\Arrow\Runtime\Columnar;
use Gld\Arrow\Runtime\Field;
use Gld\Arrow\Runtime\Type;

final class Emit
{
    public static function php(Columnar $c, string $className): string
    {
        $fields = self::fields($c);
        $className = self::ident($className, true);
        $props = '';
        $args = '';
        $build = '';
        $assign = '';
        foreach ($fields as $i => $f) {
            $prop = self::ident($f->name === '' ? 'field' . $i : $f->name, false);
            $php = self::phpType($f);
            $null = $f->nullable ? '?' : '';
            $default = self::zero($f);
            $props .= "        public {$null}{$php} \${$prop} = {$default},\n";
            $call = self::builderCall($f, $prop);
            $build .= "        \$b->{$call};\n";
            $assign .= "            \$row[" . var_export($f->name, true) . "],\n";
        }
        $props = rtrim($props, ",\n");
        return <<<PHP
<?php

declare(strict_types=1);

namespace Gld\\Arrow\\Generated;

use Gld\\Arrow\\Runtime\\TableBuilder;
use Gld\\Arrow\\Runtime\\Values;
use Gld\\Arrow\\Wire\\Ipc;

final class {$className}
{
    public function __construct(
{$props}
    ) {
    }

    public function encodedLength(): int
    {
        return strlen(\$this->encode());
    }

    public function encode(): string
    {
        \$b = new TableBuilder();
{$build}        return Ipc::writeStream(\$b->build());
    }

    public static function decode(string \$bytes): self
    {
        \$table = Ipc::readStream(\$bytes);
        \$row = Values::row(\$table, 0, 0);
        return new self(
{$assign}        );
    }
}

PHP;
    }

    /** @return list<Field> */
    private static function fields(Columnar $c): array
    {
        if (count($c->top) === 1 && $c->fields[$c->top[0]]->type === Type::T_STRUCT) {
            $out = [];
            foreach ($c->fields[$c->top[0]]->children as $id) {
                $out[] = $c->fields[$id];
            }
            return $out;
        }
        $out = [];
        foreach ($c->top as $id) {
            $out[] = $c->fields[$id];
        }
        return $out;
    }

    private static function phpType(Field $f): string
    {
        return match ($f->type) {
            Type::T_BOOL => 'bool',
            Type::T_UTF8, Type::T_LARGE_UTF8 => 'string',
            Type::T_FLOAT => 'float',
            Type::T_LIST, Type::T_LARGE_LIST => 'array',
            default => 'int',
        };
    }

    private static function zero(Field $f): string
    {
        if ($f->nullable) {
            return 'null';
        }
        return match ($f->type) {
            Type::T_BOOL => 'false',
            Type::T_UTF8, Type::T_LARGE_UTF8 => "''",
            Type::T_FLOAT => '0.0',
            Type::T_LIST, Type::T_LARGE_LIST => '[]',
            default => '0',
        };
    }

    private static function builderCall(Field $f, string $prop): string
    {
        $null = $f->nullable ? 'true' : 'false';
        $name = var_export($f->name, true);
        return match ($f->type) {
            Type::T_BOOL => "bools({$name}, [\$this->{$prop}], {$null})",
            Type::T_UTF8 => "utf8({$name}, [\$this->{$prop}], {$null})",
            Type::T_LARGE_UTF8 => "utf8({$name}, [\$this->{$prop}], {$null}, true)",
            Type::T_FLOAT => "float64({$name}, [\$this->{$prop}], {$null})",
            Type::T_INT => 'ints(' . $name . ', [$this->' . $prop . '], ' . ($f->bitWidth === 0 ? 64 : $f->bitWidth) . ', ' . ($f->signed ? 'true' : 'false') . ', ' . $null . ')',
            default => "int64({$name}, [\$this->{$prop}], {$null})",
        };
    }

    public static function ident(string $name, bool $class): string
    {
        $out = preg_replace('/[^A-Za-z0-9_]/', '_', $name) ?? 'Root';
        if ($out === '' || preg_match('/^[0-9]/', $out) === 1) {
            $out = ($class ? 'Root' : 'f_') . $out;
        }
        $reserved = [
            'class', 'function', 'list', 'array', 'string', 'int', 'float', 'bool', 'null',
            'true', 'false', 'match', 'new', 'echo', 'return', 'public', 'var', 'namespace',
            'parent', 'self', 'static', 'empty', 'isset', 'unset', 'die', 'exit', 'print',
            'require', 'include', 'throw', 'try', 'catch', 'finally', 'for', 'foreach',
            'if', 'else', 'while', 'switch', 'case', 'default', 'break', 'continue', 'as',
            'use', 'trait', 'interface', 'extends', 'implements', 'abstract', 'final',
            'private', 'protected', 'readonly', 'enum', 'yield', 'fn', 'global', 'clone',
            'and', 'or', 'xor', 'object', 'mixed', 'void', 'iterable', 'never',
        ];
        if (in_array(strtolower($out), $reserved, true)) {
            $out .= '_';
        }
        return $out;
    }
}
