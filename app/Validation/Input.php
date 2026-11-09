<?php

declare(strict_types=1);

namespace App\Validation;

use App\Core\HttpException;

final class Input
{
    public const SECTIONS = [
        'cover',
        'hero',
        'intro',
        'couple',
        'story',
        'events',
        'gallery',
        'wishes',
        'gift',
        'closing',
        'photos',
        'agenda',
        'video',
        'prayers',
        'guide',
    ];
    public const PACKAGES = ['silver', 'gold', 'platinum', 'exclusive'];

    public static function text(
        mixed $value,
        string $field,
        int $maximum,
        bool $optional = false,
    ): string {
        if ($optional && $value === null) {
            return '';
        }
        if (
            !is_string($value) ||
            (!$optional && trim($value) === '') ||
            mb_strlen(trim($value)) > $maximum
        ) {
            throw new HttpException(
                422,
                "{$field} harus berupa teks maksimal {$maximum} karakter.",
            );
        }
        return trim($value);
    }

    public static function object(mixed $value, string $field): \stdClass
    {
        if (!($value instanceof \stdClass)) {
            throw new HttpException(422, "{$field} harus berupa objek.");
        }
        return $value;
    }

    public static function number(
        mixed $value,
        float $min,
        float $max,
        string $field,
    ): int|float {
        if (
            (!is_int($value) && !is_float($value)) ||
            !is_finite((float) $value) ||
            $value < $min ||
            $value > $max
        ) {
            throw new HttpException(422, "{$field} harus di antara {$min} dan {$max}.");
        }
        return $value;
    }

    public static function pagination(array $params): array
    {
        $limit = filter_var($params['limit'] ?? 20, FILTER_VALIDATE_INT);
        $offset = filter_var($params['offset'] ?? 0, FILTER_VALIDATE_INT);
        if (
            $limit === false ||
            $limit < 1 ||
            $limit > 100 ||
            $offset === false ||
            $offset < 0
        ) {
            throw new HttpException(
                422,
                'limit harus 1–100 dan offset tidak boleh negatif.',
            );
        }
        return [$limit, $offset];
    }

    public static function photo(mixed $value): string
    {
        $url = self::text($value, 'URL foto', 2000);
        if (
            !preg_match('~^/api/media/[a-f0-9-]{36}\.(jpg|png|webp)$~D', $url) &&
            (!filter_var($url, FILTER_VALIDATE_URL) ||
                parse_url($url, PHP_URL_SCHEME) !== 'https')
        ) {
            throw new HttpException(
                422,
                'URL foto harus menggunakan HTTPS atau gambar unggahan.',
            );
        }
        return $url;
    }
}
