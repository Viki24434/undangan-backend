<?php

declare(strict_types=1);

namespace App\Validation;

use App\Core\HttpException;

final class PresentationInput
{
    public static function validate(mixed $input): object
    {
        Input::object($input, 'Konfigurasi tampilan');
        if (
            ($input->version ?? null) !== 1 ||
            !in_array($input->kind ?? null, ['wedding', 'celebration'], true)
        ) {
            throw new HttpException(422, 'Jenis tampilan template tidak didukung.');
        }
        if (
            !is_string($input->style ?? null) ||
            !preg_match('/^invitation-[a-z0-9-]{1,80}$/D', $input->style)
        ) {
            throw new HttpException(422, 'Gaya dasar template tidak valid.');
        }
        if (
            !is_array($input->palette ?? null) ||
            count($input->palette) < 2 ||
            count($input->palette) > 8
        ) {
            throw new HttpException(422, 'Palet template harus memiliki 2–8 warna.');
        }
        $palette = array_map(static function ($color) {
            if (!is_string($color) || !preg_match('/^#[a-f0-9]{6}$/iD', $color)) {
                throw new HttpException(
                    422,
                    'Palet template harus berupa kode warna hex.',
                );
            }
            return strtolower($color);
        }, $input->palette);
        $components = clone Input::object(
            $input->components ?? new \stdClass(),
            'Komponen tampilan',
        );
        $experience = $components->experience ?? null;
        $adat = $components->adat ?? null;
        unset($components->experience, $components->adat);
        $validated = DesignInput::validate(
            (object) ['version' => 1, 'components' => $components],
        )->components;
        if ($experience !== null) {
            if (!in_array($experience, ['standard', 'platinum'], true)) {
                throw new HttpException(422, 'Pengalaman template tidak valid.');
            }
            $validated->experience = $experience;
        }
        if ($adat !== null) {
            if (!is_bool($adat)) {
                throw new HttpException(422, 'Pengaturan adat harus berupa boolean.');
            }
            $validated->adat = $adat;
        }
        return (object) [
            'version' => 1,
            'kind' => $input->kind,
            'style' => $input->style,
            'palette' => $palette,
            'components' => $validated,
        ];
    }
}
