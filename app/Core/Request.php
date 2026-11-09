<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    public readonly string $method;
    public readonly string $path;

    public function __construct()
    {
        foreach ($_GET as $value) {
            if (!is_string($value)) {
                throw new HttpException(422, 'Parameter URL harus berupa teks.');
            }
        }
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $base = str_ends_with($script, '/index.php') ? dirname($script) : '/';
        if (str_ends_with($base, '/public') && !str_starts_with($path, $base . '/')) {
            $base = substr($base, 0, -7) ?: '/';
        }
        if ($base !== '/' && $base !== '.' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));
        }
        if (preg_match('/%(?![a-f0-9]{2})/i', $path)) {
            throw new HttpException(400, 'URL tidak valid.');
        }
        $this->path = '/' . trim($path, '/');
    }

    public function header(string $name): string
    {
        $key = strtoupper(str_replace('-', '_', $name));
        return (string) ($_SERVER['HTTP_' . $key] ?? ($_SERVER[$key] ?? ''));
    }

    public function bearer(): string
    {
        $header = $this->header('Authorization');
        return str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
    }

    public function raw(int $maximum): string
    {
        if ((int) $this->header('Content-Length') > $maximum) {
            throw new HttpException(413, 'Ukuran kiriman melebihi batas.');
        }
        $stream = fopen('php://input', 'rb');
        $body = stream_get_contents($stream, $maximum + 1);
        fclose($stream);
        if (strlen($body) > $maximum) {
            throw new HttpException(413, 'Ukuran kiriman melebihi batas.');
        }
        return $body;
    }

    public function json(int $maximum = 32768): \stdClass
    {
        if (
            strtolower(trim(explode(';', $this->header('Content-Type'))[0])) !==
            'application/json'
        ) {
            throw new HttpException(415, 'Gunakan Content-Type application/json.');
        }
        $raw = $this->raw($maximum);
        try {
            $body = Json::decode($raw);
        } catch (\JsonException) {
            throw new HttpException(400, 'Body harus berupa objek JSON yang valid.');
        }
        if (!($body instanceof \stdClass)) {
            throw new HttpException(400, 'Body harus berupa objek JSON yang valid.');
        }
        return $body;
    }
}
