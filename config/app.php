<?php

declare(strict_types=1);

use App\Core\Env;

$root = dirname(__DIR__);

return [
    'root' => $root,
    'origins' => Env::get('CORS_ORIGINS'),
    'admin_token' => Env::get('ADMIN_TOKEN'),
    'secure_cookie' => Env::get('COOKIE_SECURE', 'false') === 'true',
    'cookie_path' => Env::get('COOKIE_PATH', '/'),
    'storage' => Env::get('STORAGE_PATH', $root . '/storage'),
    'media' => Env::get('MEDIA_PATH', $root . '/data/uploads'),
    'photos' => Env::get('GUEST_PHOTOS_PATH', $root . '/data/guest-photos'),
    'submissions' => Env::get('SUBMISSIONS_PATH', $root . '/data/customer-submissions'),
    'database' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => Env::integer('DB_PORT', 3306),
        'name' => Env::get('DB_NAME', 'undangan_db'),
        'user' => Env::get('DB_USER', 'root'),
        'password' => Env::get('DB_PASSWORD'),
    ],
];
