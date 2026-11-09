<?php

declare(strict_types=1);

namespace App\Core;

final class Env
{
    private static array $values = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (
                !preg_match(
                    '/^(?:export\s+)?([A-Z_][A-Z0-9_]*)\s*=\s*(.*)$/',
                    $line,
                    $match,
                )
            ) {
                throw new \RuntimeException('Format .env tidak valid.');
            }
            $value = trim($match[2]);
            if (strlen($value) >= 2 && in_array($value[0], ['"', "'"], true)) {
                if ($value[-1] !== $value[0]) {
                    throw new \RuntimeException('Tanda kutip .env tidak lengkap.');
                }
                $value = substr($value, 1, -1);
            } else {
                $value = preg_replace('/\s+#.*$/', '', $value);
            }
            self::$values[$match[1]] = $value;
        }
    }

    public static function get(string $key, string $fallback = ''): string
    {
        $value = getenv($key);
        return $value !== false ? $value : self::$values[$key] ?? $fallback;
    }

    public static function integer(string $key, int $fallback, int $max = 65535): int
    {
        $value = self::get($key, (string) $fallback);
        if (!ctype_digit($value) || (int) $value < 1 || (int) $value > $max) {
            throw new \RuntimeException("{$key} tidak valid.");
        }
        return (int) $value;
    }
}
