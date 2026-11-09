<?php

declare(strict_types=1);

use App\Core\Env;

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = __DIR__ . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

Env::load(__DIR__ . '/.env');
date_default_timezone_set('UTC');

return require __DIR__ . '/config/app.php';
