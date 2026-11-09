<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Core\Request;

final class RateLimiter
{
    public function __construct(private readonly string $directory) {}

    public function check(Request $request, string $scope): void
    {
        $maximum = match ($scope) {
            'customer-submissions' => 5,
            'submission-images' => 120,
            default => 20,
        };
        Files::directory($this->directory);
        $path =
            $this->directory .
            '/' .
            hash('sha256', $scope . ':' . ($_SERVER['REMOTE_ADDR'] ?? 'local')) .
            '.json';
        $file = fopen($path, 'c+');
        if (!$file || !flock($file, LOCK_EX)) {
            throw new \RuntimeException(
                'Penyimpanan pembatas permintaan tidak dapat diakses.',
            );
        }
        try {
            $value = json_decode(stream_get_contents($file), true) ?: [];
            if (($value['expires'] ?? 0) <= time()) {
                $value = ['count' => 0, 'expires' => time() + 60];
            }
            $value['count']++;
            rewind($file);
            ftruncate($file, 0);
            fwrite($file, json_encode($value));
            if ($value['count'] > $maximum) {
                header('Retry-After: 60');
                throw new HttpException(
                    429,
                    'Terlalu banyak kiriman. Coba lagi dalam satu menit.',
                );
            }
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
        if (random_int(1, 100) === 1) {
            foreach (glob($this->directory . '/*.json') as $old) {
                if (filemtime($old) < time() - 120) {
                    @unlink($old);
                }
            }
        }
    }
}
