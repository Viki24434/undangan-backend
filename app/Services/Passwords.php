<?php

declare(strict_types=1);

namespace App\Services;

final class Passwords
{
    public static function hash(string $password): string
    {
        return password_hash(hash('sha256', $password), PASSWORD_DEFAULT);
    }

    public static function verify(string $password, string $hash): bool
    {
        if (str_contains($hash, ':')) {
            return LegacyScrypt::verify($password, $hash);
        }
        return password_verify(hash('sha256', $password), $hash);
    }

    public static function needsUpgrade(string $hash): bool
    {
        return str_contains($hash, ':') ||
            password_needs_rehash($hash, PASSWORD_DEFAULT);
    }
}
