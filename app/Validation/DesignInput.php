<?php

declare(strict_types=1);

namespace App\Validation;

use App\Core\HttpException;

final class DesignInput
{
    private const ENTRIES = [
        'inherit',
        'none',
        'fade',
        'left',
        'right',
        'rise',
        'zoom',
        'type',
    ];
    private const MOTIONS = [
        'inherit',
        'none',
        'sway',
        'float',
        'pulse',
        'spin',
        'flutter',
    ];
    private const COMPONENTS = [
        'cover' => [1, 2, 3, 4],
        'hero' => [1, 2, 3, 4],
        'couple' => [1, 2, 3, 4],
        'story' => [1, 2, 3],
        'countdown' => [1, 2, 3, 4],
        'gallery' => [0, 1, 2, 3, 4, 5],
        'gift' => [1, 2, 3],
        'wishes' => [1, 2, 3],
        'intro' => [1, 2, 3],
        'closing' => [1, 2, 3],
    ];

    public static function validate(mixed $input): \stdClass
    {
        Input::object($input, 'Desain');
        if (($input->version ?? null) !== 1) {
            throw new HttpException(422, 'Format desain tidak didukung.');
        }
        $result = (object) [
            'version' => 1,
            'sections' => new \stdClass(),
            'native' => new \stdClass(),
        ];
        if (isset($input->components)) {
            Input::object($input->components, 'Variasi komponen');
            $result->components = new \stdClass();
            foreach ($input->components as $key => $value) {
                if (
                    !isset(self::COMPONENTS[$key]) ||
                    !in_array($value, self::COMPONENTS[$key], true)
                ) {
                    throw new HttpException(
                        422,
                        'Pilihan variasi komponen tidak tersedia.',
                    );
                }
                $result->components->$key = $value;
            }
        }
        $count = 0;
        foreach (
            Input::object($input->sections ?? new \stdClass(), 'Bagian desain')
            as $key => $section
        ) {
            if (!in_array($key, Input::SECTIONS, true)) {
                throw new HttpException(422, 'Bagian desain tidak valid.');
            }
            Input::object($section, 'Bagian desain');
            $items = $section->items ?? [];
            if (!is_array($items) || count($items) > 40) {
                throw new HttpException(
                    422,
                    'Maksimal 40 ornamen tambahan per bagian.',
                );
            }
            $ids = [];
            $validated = [];
            foreach ($items as $item) {
                $layer = self::layer($item);
                if (isset($ids[$layer->id])) {
                    throw new HttpException(422, 'ID ornamen harus unik.');
                }
                $ids[$layer->id] = true;
                $validated[] = $layer;
            }
            $reveal = $section->reveal ?? 'inherit';
            if (!in_array($reveal, self::ENTRIES, true)) {
                throw new HttpException(422, 'Animasi bagian tidak tersedia.');
            }
            $result->sections->$key = (object) [
                'items' => $validated,
                'reveal' => $reveal,
                'duration' => Input::number(
                    $section->duration ?? 1,
                    0.2,
                    8,
                    'Durasi bagian',
                ),
            ];
            $count += count($items);
        }
        foreach (
            Input::object($input->native ?? new \stdClass(), 'Ornamen bawaan')
            as $key => $item
        ) {
            if (
                !preg_match(
                    '/^(' .
                        implode('|', Input::SECTIONS) .
                        '):(?:[a-z0-9-]{1,150}:)?\d{1,3}$/D',
                    $key,
                )
            ) {
                throw new HttpException(422, 'Referensi ornamen bawaan tidak valid.');
            }
            $result->native->$key = self::layer($item, true);
            $count++;
        }
        if ($count > 250) {
            throw new HttpException(
                422,
                'Maksimal 250 pengaturan ornamen per template.',
            );
        }
        return $result;
    }

    private static function layer(mixed $item, bool $native = false): \stdClass
    {
        Input::object($item, 'Lapisan desain');
        $result = new \stdClass();
        foreach (
            [
                ['x', -200, 300, 0],
                ['y', -200, 300, 0],
                ['width', 1, 400, 30],
                ['rotate', -360, 360, 0],
                ['opacity', 0, 1, 1],
                ['zIndex', -5, 50, 3],
                ['duration', 0.2, 30, 6],
                ['delay', 0, 10, 0],
                ['entryDuration', 0.2, 8, 1],
            ]
            as [$key, $min, $max, $default]
        ) {
            $result->$key = Input::number($item->$key ?? $default, $min, $max, $key);
        }
        if (isset($item->height)) {
            $result->height = Input::number($item->height, 1, 400, 'Tinggi');
        }
        foreach (['flipX', 'flipY', 'hidden'] as $key) {
            if (property_exists($item, $key) && !is_bool($item->$key)) {
                throw new HttpException(422, "{$key} harus berupa boolean.");
            }
            $result->$key = $item->$key ?? false;
        }
        $result->entry = $item->entry ?? ($native ? 'inherit' : 'fade');
        $result->motion = $item->motion ?? ($native ? 'inherit' : 'none');
        if (
            !in_array($result->entry, self::ENTRIES, true) ||
            !in_array($result->motion, self::MOTIONS, true)
        ) {
            throw new HttpException(422, 'Pilihan animasi tidak tersedia.');
        }
        if (!$native) {
            if (
                !is_string($item->id ?? null) ||
                !preg_match('/^[\w-]{1,60}$/D', $item->id)
            ) {
                throw new HttpException(422, 'ID lapisan tidak valid.');
            }
            $result->id = $item->id;
            $result->label = is_string($item->label ?? null)
                ? mb_substr($item->label, 0, 100)
                : 'Ornamen';
        }
        if (
            !$native ||
            property_exists($item, 'asset') ||
            property_exists($item, 'src')
        ) {
            if (property_exists($item, 'asset')) {
                if (
                    !is_string($item->asset) ||
                    !preg_match('/^[a-z0-9-]{1,120}$/D', $item->asset)
                ) {
                    throw new HttpException(422, 'Aset ornamen tidak valid.');
                }
                $result->asset = $item->asset;
            } else {
                $src = $item->src ?? null;
                if (
                    !is_string($src) ||
                    strlen($src) > 2000 ||
                    str_contains($src, '..') ||
                    !(
                        preg_match(
                            '~^/api/media/[a-f0-9-]{36}\.(png|webp|jpg)$~D',
                            $src,
                        ) || preg_match('~^/assets/[a-z0-9_./% -]+$~iD', $src)
                    )
                ) {
                    throw new HttpException(
                        422,
                        'Gunakan aset perpustakaan atau gambar yang diunggah.',
                    );
                }
                $result->src = $src;
            }
        }
        return $result;
    }
}
