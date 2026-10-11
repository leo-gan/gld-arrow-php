<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $root = dirname(__DIR__);
    $map = [
        'Gld\\Arrow\\Generated\\' => $root . '/tests/generated/',
        'Gld\\Arrow\\' => __DIR__ . '/',
    ];
    foreach ($map as $prefix => $dir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        $path = $dir . $rel;
        if (is_file($path)) {
            require $path;
            return;
        }
    }
});
