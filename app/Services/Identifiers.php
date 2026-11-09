<?php

declare(strict_types=1);

namespace App\Services;

final class Identifiers
{
    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 15) | 64);
        $data[8] = chr((ord($data[8]) & 63) | 128);
        return self::format(bin2hex($data));
    }

    public static function format(string $hex): string
    {
        return implode('-', [
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ]);
    }

    public static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function milliseconds(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    public static function normalizedName(string $name): string
    {
        $name = \Normalizer::normalize($name, \Normalizer::FORM_KC);
        return preg_replace('/\s+/u', ' ', mb_strtolower($name));
    }
}
