<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

try {
    $root = dirname(__DIR__);
    if (!is_file($root . '/.env')) {
        $example = file_get_contents($root . '/.env.example');
        $example = str_replace(
            'replace-with-a-random-token-of-at-least-32-characters',
            bin2hex(random_bytes(32)),
            $example,
        );
        file_put_contents($root . '/.env', $example, LOCK_EX);
    }
    $config = require $root . '/bootstrap.php';
    foreach (['pdo_mysql', 'mbstring', 'intl'] as $extension) {
        if (!extension_loaded($extension)) {
            throw new RuntimeException("Aktifkan extension {$extension} pada php.ini.");
        }
    }
    App\Services\DatabaseSetup::run($config);
    foreach (
        [
            $config['storage'] . '/logs',
            $config['storage'] . '/rate-limits',
            $config['media'],
            $config['photos'],
            $config['submissions'],
        ]
        as $directory
    ) {
        App\Services\Files::directory($directory);
    }
    echo "Setup MySQL selesai. Data yang sudah ada tetap tersimpan.\n";
    echo "Aktifkan Apache XAMPP atau jalankan: php -S 127.0.0.1:3001 -t public router.php\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Setup gagal: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
