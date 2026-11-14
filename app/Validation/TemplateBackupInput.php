<?php

declare(strict_types=1);

namespace App\Validation;

use App\Core\HttpException;

final class TemplateBackupInput
{
    public const FORMAT = 'undangan-theme-backup';
    public const MAX_TEMPLATES = 500;
    public const MAX_ASSETS = 1000;
    public const MAX_BYTES = 104857600;
    public const MEDIA_PATTERN = '~^/api/media/([a-f0-9-]{36}\.(png|jpg|webp))$~D';

    public static function id(mixed $value): string
    {
        if (
            !is_string($value) ||
            !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,79}$/D', $value)
        ) {
            throw new HttpException(422, 'ID template pada cadangan tidak valid.');
        }
        return $value;
    }

    public static function references(mixed $value): array
    {
        $result = [];
        $visit = static function ($item) use (&$visit, &$result) {
            if (is_string($item) && preg_match(self::MEDIA_PATTERN, $item)) {
                $result[$item] = true;
            } elseif (is_array($item) || is_object($item)) {
                foreach ($item as $child) {
                    $visit($child);
                }
            }
        };
        $visit($value);
        return array_keys($result);
    }

    public static function read(mixed $input): object
    {
        Input::object($input, 'Cadangan tema');
        if (
            ($input->format ?? null) !== self::FORMAT ||
            ($input->version ?? null) !== 1
        ) {
            throw new HttpException(
                422,
                'Format atau versi cadangan tema tidak didukung.',
            );
        }
        $templates = $input->templates ?? null;
        if (
            !is_array($templates) ||
            count($templates) < 1 ||
            count($templates) > self::MAX_TEMPLATES
        ) {
            throw new HttpException(422, 'Cadangan harus berisi 1–500 template.');
        }
        $ids = [];
        $validated = [];
        foreach ($templates as $template) {
            Input::object($template, 'Template cadangan');
            $id = self::id($template->id ?? null);
            if (isset($ids[$id])) {
                throw new HttpException(
                    422,
                    'ID template dalam cadangan tidak boleh berulang.',
                );
            }
            $ids[$id] = true;
            if (!in_array($template->packageId ?? null, Input::PACKAGES, true)) {
                throw new HttpException(
                    422,
                    'Paket template pada cadangan tidak valid.',
                );
            }
            $data = TemplateInput::data($template->data ?? null);
            if (!isset($data->presentation)) {
                throw new HttpException(
                    422,
                    'Cadangan tidak memiliki konfigurasi tampilan template.',
                );
            }
            if (!is_bool($template->isCustom ?? null)) {
                throw new HttpException(
                    422,
                    'Status template custom pada cadangan tidak valid.',
                );
            }
            $img = Input::text($template->img ?? null, 'Gambar katalog', 2000, true);
            if (
                $img !== '' &&
                !preg_match(self::MEDIA_PATTERN, $img) &&
                !preg_match('~^/assets/[a-z0-9_./% -]+$~iD', $img) &&
                (!filter_var($img, FILTER_VALIDATE_URL) ||
                    parse_url($img, PHP_URL_SCHEME) !== 'https')
            ) {
                throw new HttpException(
                    422,
                    'URL gambar katalog pada cadangan tidak valid.',
                );
            }
            $validated[] = (object) [
                'id' => $id,
                'name' => Input::text($template->name ?? null, 'Nama template', 160),
                'category' => Input::text($template->category ?? null, 'Kategori', 80),
                'description' => Input::text(
                    $template->description ?? null,
                    'Deskripsi',
                    1000,
                    true,
                ),
                'img' => $img,
                'packageId' => $template->packageId,
                'rendererId' => self::id($template->rendererId ?? $id),
                'isCustom' => $template->isCustom,
                'data' => $data,
            ];
        }
        $references = array_fill_keys(self::references($validated), true);
        $assets = $input->assets ?? null;
        if (!is_array($assets) || count($assets) > self::MAX_ASSETS) {
            throw new HttpException(
                422,
                'Daftar gambar cadangan tidak valid atau melebihi 1.000 gambar.',
            );
        }
        $sources = [];
        $bytes = 0;
        $images = [];
        foreach ($assets as $asset) {
            Input::object($asset, 'Gambar cadangan');
            $source = $asset->source ?? null;
            if (
                !is_string($source) ||
                !preg_match(self::MEDIA_PATTERN, $source, $match) ||
                !isset($references[$source]) ||
                isset($sources[$source]) ||
                ($asset->path ?? null) !== 'uploads/' . $match[1] ||
                !is_string($asset->sha256 ?? null) ||
                !preg_match('/^[a-f0-9]{64}$/D', $asset->sha256) ||
                !is_int($asset->size ?? null) ||
                $asset->size < 1 ||
                $asset->size > 5242880
            ) {
                throw new HttpException(422, 'Informasi gambar cadangan tidak valid.');
            }
            $sources[$source] = true;
            $bytes += $asset->size;
            $images[] = (object) [
                'source' => $source,
                'path' => $asset->path,
                'sha256' => $asset->sha256,
                'size' => $asset->size,
            ];
        }
        if (count($sources) !== count($references)) {
            throw new HttpException(
                422,
                'Ada gambar unggahan yang belum disertakan dalam cadangan.',
            );
        }
        if ($bytes > self::MAX_BYTES) {
            throw new HttpException(
                413,
                'Total gambar melebihi 100 MB. Ekspor lebih sedikit tema.',
            );
        }
        return (object) ['templates' => $validated, 'assets' => $images];
    }
}
