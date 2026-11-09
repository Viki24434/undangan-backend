<?php

declare(strict_types=1);

namespace App\Services;

final class Files
{
    public static function directory(string $directory): void
    {
        if (
            !is_dir($directory) &&
            !mkdir($directory, 0750, true) &&
            !is_dir($directory)
        ) {
            throw new \RuntimeException('Folder penyimpanan tidak dapat dibuat.');
        }
    }

    public static function write(string $path, string $contents): void
    {
        self::directory(dirname($path));
        $file = fopen($path, 'xb');
        if (!$file) {
            throw new \RuntimeException('File tidak dapat dibuat.');
        }
        try {
            if (fwrite($file, $contents) !== strlen($contents)) {
                throw new \RuntimeException('File belum selesai disimpan.');
            }
        } finally {
            fclose($file);
        }
    }

    public static function remove(string $path): void
    {
        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException('File tidak dapat dihapus.');
        }
    }

    public static function cleanSubmission(string $directory, string $id): void
    {
        if (!preg_match('/^[a-f0-9-]{36}$/D', $id)) {
            throw new \RuntimeException('Folder kiriman tidak valid.');
        }
        $target = rtrim($directory, '/\\') . '/' . $id;
        if (!is_dir($target)) {
            return;
        }
        if (is_link($target)) {
            throw new \RuntimeException('Folder kiriman tidak valid.');
        }
        foreach (new \DirectoryIterator($target) as $file) {
            if (!$file->isDot()) {
                if (!$file->isFile() || $file->isLink()) {
                    throw new \RuntimeException('Isi folder kiriman tidak valid.');
                }
                self::remove($file->getPathname());
            }
        }
        rmdir($target);
    }
}
