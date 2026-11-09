<?php

declare(strict_types=1);

namespace App\Validation;

use App\Core\HttpException;

final class ThemeInput
{
    private const COLORS = [
        'background',
        'surface',
        'paper',
        'text',
        'heading',
        'muted',
        'accent',
        'border',
        'buttonBackground',
        'buttonText',
        'inputBackground',
        'inputText',
        'navigation',
        'navigationText',
        'overlay',
    ];
    private const GRADIENTS = [
        'background',
        'surface',
        'paper',
        'heading',
        'accent',
        'buttonBackground',
        'inputBackground',
        'navigation',
        'overlay',
    ];

    public static function validate(mixed $input, bool $section = false): \stdClass
    {
        Input::object($input, 'Tema');
        $result = new \stdClass();
        foreach ($input as $key => $value) {
            if (in_array($key, self::COLORS, true)) {
                $result->$key = self::color($value);
            } elseif ($key === 'gradients') {
                $result->gradients = self::gradients($value);
            } elseif ($key === 'overlayOpacity') {
                $result->$key = Input::number($value, 0, 1, 'Kepekatan lapisan foto');
            } elseif (
                !$section &&
                $key === 'font' &&
                in_array($value, ['default', 'classic', 'modern'], true)
            ) {
                $result->font = $value;
            } elseif (!$section && $key === 'sections') {
                Input::object($value, 'Warna bagian');
                $result->sections = new \stdClass();
                foreach ($value as $name => $colors) {
                    if (!in_array($name, Input::SECTIONS, true)) {
                        throw new HttpException(422, 'Bagian warna tidak tersedia.');
                    }
                    $result->sections->$name = self::validate($colors, true);
                }
            } else {
                throw new HttpException(422, "Pengaturan tema {$key} tidak tersedia.");
            }
        }
        return $result;
    }

    private static function color(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^#[\da-f]{6}$/iD', $value)) {
            throw new HttpException(422, 'Warna harus berupa kode hex enam digit.');
        }
        return strtolower($value);
    }

    private static function gradients(mixed $input): \stdClass
    {
        Input::object($input, 'Gradasi');
        $result = new \stdClass();
        foreach ($input as $key => $gradient) {
            if (
                !in_array($key, self::GRADIENTS, true) ||
                !($gradient instanceof \stdClass) ||
                !is_array($gradient->stops ?? null) ||
                count($gradient->stops) < 2 ||
                count($gradient->stops) > 5
            ) {
                throw new HttpException(422, 'Gradasi harus memiliki 2–5 warna.');
            }
            $stops = [];
            foreach ($gradient->stops as $stop) {
                Input::object($stop, 'Warna gradasi');
                $stops[] = (object) [
                    'color' => self::color($stop->color ?? null),
                    'position' => Input::number(
                        $stop->position ?? null,
                        0,
                        100,
                        'Posisi gradasi',
                    ),
                    'opacity' => Input::number(
                        $stop->opacity ?? 1,
                        0,
                        1,
                        'Opasitas gradasi',
                    ),
                ];
            }
            $result->$key = (object) [
                'angle' => Input::number(
                    $gradient->angle ?? null,
                    0,
                    360,
                    'Sudut gradasi',
                ),
                'stops' => $stops,
            ];
        }
        return $result;
    }
}
