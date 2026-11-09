<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Cors;

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

try {
    $config = require dirname(__DIR__) . '/bootstrap.php';
    $request = new Request();
    Cors::apply($request, $config['origins']);
    $db = new Database($config['database']);
    $route = require dirname(__DIR__) . '/routes/api.php';
    Response::send($route($db, $request, $config));
} catch (Throwable $error) {
    $status =
        $error instanceof HttpException
            ? $error->status
            : ($error instanceof PDOException && ($error->errorInfo[1] ?? null) === 1062
                ? 409
                : 500);
    if ($status === 500) {
        error_log((string) $error);
    }
    http_response_code($status);
    $message =
        $status === 500
            ? 'Terjadi kesalahan pada server. Periksa log PHP dan konfigurasi backend.'
            : ($status === 409 && !($error instanceof HttpException)
                ? 'Data unik sudah digunakan.'
                : $error->getMessage());
    echo json_encode(
        ['success' => false, 'message' => $message],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );
}
