<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Response;
use App\Models\ShareModel;
use App\Services\Files;
use App\Services\ShareDocument;

final class ShareController
{
    public function __construct(
        private readonly ShareModel $model,
        private readonly array $config,
    ) {}

    private function url(string $configured): string
    {
        $url = parse_url($configured);
        if (
            !$url ||
            !in_array($url['scheme'] ?? '', ['http', 'https'], true) ||
            empty($url['host']) ||
            isset($url['user']) ||
            isset($url['pass']) ||
            isset($url['query']) ||
            isset($url['fragment'])
        ) {
            throw new \RuntimeException(
                'Isi PUBLIC_SITE_URL dan PUBLIC_API_URL pada .env dengan URL website dan API.',
            );
        }
        return rtrim($configured, '/');
    }

    private function record(string $value, bool $admin = false): array
    {
        $row = $this->model->find($value, $admin);
        if (!$row || (!$admin && $row['status'] !== 'published')) {
            throw new HttpException(404, 'Undangan tidak ditemukan.');
        }
        return $row;
    }

    private function source(array $row): string
    {
        foreach (['image', 'template_image', 'catalog_image'] as $key) {
            if (!empty($row[$key]) && $row[$key] !== 'null') {
                return $row[$key];
            }
        }
        return '';
    }

    private function version(array $row): string
    {
        return substr(
            hash('sha256', 'ciptala-v1|' . $this->source($row) . '|' . $row['updated_at']),
            0,
            16,
        );
    }

    public function metadata(string $value, bool $admin = false): array
    {
        $row = $this->record($value, $admin);
        $site = $this->url($this->config['public_site_url']);
        $api = $this->url($this->config['public_api_url'] ?: $site . '/api');
        $source = $this->source($row);
        $generated =
            preg_match('~^/api/media/[a-f0-9-]{36}\.(jpg|png|webp)$~D', $source) ===
                1 || $source === '';
        $original = str_starts_with($source, '/api/')
            ? $api . substr($source, 4)
            : (str_starts_with($source, '/')
                ? $site . $source
                : $source);
        if (
            !$generated &&
            (!filter_var($original, FILTER_VALIDATE_URL) ||
                !in_array(
                    parse_url($original, PHP_URL_SCHEME),
                    ['http', 'https'],
                    true,
                ))
        ) {
            $generated = true;
            $original = '';
        }
        $date = (new \DateTimeImmutable($row['event_date']))->setTimezone(
            new \DateTimeZone('Asia/Jakarta'),
        );
        $formatted = (new \IntlDateFormatter(
            'id_ID',
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'Asia/Jakarta',
        ))->format($date);
        $occasion =
            $row['kind'] === 'wedding'
                ? 'Pernikahan'
                : ($row['category'] ?:
                'Acara istimewa');
        $metadata = [
            'title' => $row['title'],
            'description' =>
                $occasion .
                ' · ' .
                $formatted .
                '. Kehadiran dan doa baik Anda melengkapi kebahagiaan kami.',
            'url' => $site . '/u/' . rawurlencode($row['slug']),
            'image' => $generated
                ? $api .
                    '/share-images/' .
                    rawurlencode($row['slug']) .
                    '/' .
                    $this->version($row) .
                    '.jpg'
                : $original,
            'generatedImage' => $generated,
            'previewImage' => $original,
            'published' => $row['status'] === 'published',
        ];
        $metadata['head'] = ShareDocument::head($metadata);
        return $metadata;
    }

    public function image(string $slug, string $version): array
    {
        $row = $this->record($slug);
        if ($version !== $this->version($row) . '.jpg') {
            throw new HttpException(404, 'Gambar tidak ditemukan.');
        }
        $directory = $this->config['storage'] . '/share-images';
        $target = $directory . '/' . $row['id'] . '-' . $version;
        if (!is_file($target)) {
            if (!extension_loaded('gd')) {
                throw new HttpException(
                    503,
                    'Aktifkan extension GD untuk gambar preview.',
                );
            }
            $canvas = imagecreatetruecolor(1200, 630);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 246, 241, 231));
            $source = $this->source($row);
            $image = false;
            if (
                preg_match(
                    '~^/api/media/([a-f0-9-]{36}\.(jpg|png|webp))$~D',
                    $source,
                    $match,
                )
            ) {
                $file = $this->config['media'] . '/' . $match[1];
                $size = is_file($file) ? @getimagesize($file) : false;
                if (
                    $size &&
                    $size[0] * $size[1] <= 12000000 &&
                    filesize($file) <= 5242880
                ) {
                    $image = @imagecreatefromstring(file_get_contents($file));
                }
            }
            if ($image) {
                $width = imagesx($image);
                $height = imagesy($image);
                $scale = max(1200 / $width, 630 / $height);
                $cropWidth = (int) round(1200 / $scale);
                $cropHeight = (int) round(630 / $scale);
                imagecopyresampled(
                    $canvas,
                    $image,
                    0,
                    0,
                    (int) (($width - $cropWidth) / 2),
                    (int) (($height - $cropHeight) / 2),
                    1200,
                    630,
                    $cropWidth,
                    $cropHeight,
                );
                imagedestroy($image);
            } else {
                imagestring(
                    $canvas,
                    5,
                    550,
                    300,
                    'Ciptala',
                    imagecolorallocate($canvas, 36, 62, 53),
                );
            }
            Files::directory($directory);
            $temporary = $directory . '/' . bin2hex(random_bytes(16)) . '.tmp';
            try {
                if (!imagejpeg($canvas, $temporary, 85)) {
                    throw new \RuntimeException('Gambar preview belum dapat dibuat.');
                }
                if (!rename($temporary, $target) && !is_file($target)) {
                    throw new \RuntimeException('Gambar preview belum dapat disimpan.');
                }
            } finally {
                imagedestroy($canvas);
                if (is_file($temporary)) {
                    Files::remove($temporary);
                }
            }
        }
        return Response::file($target, 'image/jpeg');
    }
}
