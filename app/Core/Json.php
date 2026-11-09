<?php

declare(strict_types=1);

namespace App\Core;

final class Json
{
    public static function encode(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    public static function decode(string $value): mixed
    {
        return json_decode($value, false, 64, JSON_THROW_ON_ERROR);
    }

    public static function object(?string $value): \stdClass
    {
        $data = self::decode($value ?: '{}');
        return $data instanceof \stdClass ? $data : new \stdClass();
    }

    public static function merge(object ...$values): \stdClass
    {
        return (object) array_merge(...array_map('get_object_vars', $values));
    }
}
