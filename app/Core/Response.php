<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function data(
        mixed $data,
        int $status = 200,
        ?array $meta = null,
    ): array {
        return ['status' => $status, 'data' => $data, 'meta' => $meta];
    }

    public static function file(
        string $path,
        string $mime,
        ?string $download = null,
        bool $private = false,
    ): array {
        if (!is_file($path)) {
            throw new HttpException(404, 'Gambar tidak ditemukan.');
        }
        return compact('path', 'mime', 'download', 'private') + ['status' => 200];
    }

    public static function send(array $result): never
    {
        http_response_code($result['status']);
        if (isset($result['path'])) {
            header('Content-Type: ' . $result['mime']);
            header(
                'Cache-Control: ' .
                    ($result['private'] || $result['download']
                        ? 'private, no-store'
                        : 'public, max-age=31536000, immutable'),
            );
            if ($result['download']) {
                header(
                    'Content-Disposition: attachment; filename="' .
                        basename($result['download']) .
                        '"',
                );
            }
            header('Content-Length: ' . filesize($result['path']));
            readfile($result['path']);
        } else {
            $payload = ['success' => true, 'data' => $result['data']];
            if ($result['meta'] !== null) {
                $payload['meta'] = $result['meta'];
            }
            echo Json::encode($payload);
        }
        exit();
    }
}
