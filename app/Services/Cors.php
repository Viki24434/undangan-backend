<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Core\Request;

final class Cors
{
    public static function apply(Request $request, string $configured): void
    {
        $origins = [];
        foreach (array_filter(array_map('trim', explode(',', $configured))) as $entry) {
            $url = parse_url($entry);
            if (
                !$url ||
                !in_array($url['scheme'] ?? '', ['http', 'https'], true) ||
                empty($url['host']) ||
                isset($url['user']) ||
                isset($url['pass'])
            ) {
                throw new \RuntimeException(
                    'Isi CORS_ORIGINS pada .env dengan origin HTTP/HTTPS yang valid.',
                );
            }
            $port = $url['port'] ?? null;
            $suffix =
                $port &&
                !(
                    ($url['scheme'] === 'http' && $port === 80) ||
                    ($url['scheme'] === 'https' && $port === 443)
                )
                    ? ':' . $port
                    : '';
            $origins[] = strtolower($url['scheme'] . '://' . $url['host']) . $suffix;
        }
        if (!$origins) {
            throw new \RuntimeException('CORS_ORIGINS wajib diisi pada .env backend.');
        }
        header('Vary: Origin');
        $origin = $request->header('Origin');
        if ($origin !== '' && !in_array($origin, $origins, true)) {
            throw new HttpException(
                403,
                "Origin \"{$origin}\" tidak diizinkan. Periksa CORS_ORIGINS pada .env backend.",
            );
        }
        if ($origin !== '') {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
        }
        if ($request->method === 'OPTIONS') {
            header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
            header(
                'Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token',
            );
            http_response_code(204);
            exit();
        }
    }
}
