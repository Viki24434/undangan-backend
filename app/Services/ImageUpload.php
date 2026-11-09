<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Core\Request;

final class ImageUpload
{
    public const FORMATS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public static function read(Request $request): array
    {
        $mime = strtolower(trim(explode(';', $request->header('Content-Type'))[0]));
        $extension = self::FORMATS[$mime] ?? null;
        if (!$extension) {
            throw new HttpException(415, 'Gunakan JPG, PNG, atau WebP.');
        }
        $buffer = $request->raw(5 * 1024 * 1024);
        $valid = match ($extension) {
            'jpg' => str_starts_with($buffer, "\xff\xd8\xff"),
            'png' => str_starts_with($buffer, "\x89PNG\r\n\x1a\n"),
            'webp' => substr($buffer, 0, 4) === 'RIFF' &&
                substr($buffer, 8, 4) === 'WEBP',
        };
        if (!$valid) {
            throw new HttpException(422, 'Isi file bukan gambar yang valid.');
        }
        return compact('buffer', 'mime', 'extension');
    }
}
