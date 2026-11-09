<?php

declare(strict_types=1);

namespace App\Validation;

use App\Core\HttpException;
use App\Services\Identifiers;

final class GuestListInput
{
    public static function validate(object $body): array
    {
        if (
            !is_int($body->revision ?? null) ||
            $body->revision < 0 ||
            $body->revision > 9007199254740991
        ) {
            throw new HttpException(422, 'Versi daftar tamu tidak valid.');
        }
        if (!is_array($body->guests ?? null) || count($body->guests) > 5000) {
            throw new HttpException(
                422,
                'Daftar tamu maksimal 5.000 nama per undangan.',
            );
        }
        $ids = [];
        $names = [];
        $guests = [];
        foreach ($body->guests as $index => $guest) {
            Input::object($guest, 'Tamu baris ' . ($index + 1));
            $id = Input::text($guest->id ?? null, 'ID tamu', 36);
            if (!preg_match('/^[a-zA-Z0-9-]{1,36}$/D', $id) || isset($ids[$id])) {
                throw new HttpException(422, 'ID tamu harus unik dan valid.');
            }
            $ids[$id] = true;
            $name = preg_replace(
                '/\s+/u',
                ' ',
                Input::text($guest->name ?? null, 'Nama tamu', 80),
            );
            $key = Identifiers::normalizedName($name);
            if (isset($names[$key])) {
                throw new HttpException(
                    422,
                    "Nama \"{$name}\" sudah ada. Tambahkan pembeda jika orangnya berbeda.",
                );
            }
            $names[$key] = true;
            $phone = Input::text($guest->phone ?? '', 'WhatsApp tamu', 30, true);
            if ($phone !== '' && !preg_match('/^\+?[\d\s()-]{6,30}$/D', $phone)) {
                throw new HttpException(422, 'Nomor WhatsApp tamu tidak valid.');
            }
            if (property_exists($guest, 'sent') && !is_bool($guest->sent)) {
                throw new HttpException(422, 'Status kirim tamu tidak valid.');
            }
            $guests[] = (object) [
                'id' => $id,
                'name' => $name,
                'phone' => $phone,
                'group' => Input::text($guest->group ?? '', 'Kelompok tamu', 60, true),
                'note' => Input::text($guest->note ?? '', 'Catatan tamu', 200, true),
                'sent' => $guest->sent ?? false,
            ];
        }
        return $guests;
    }

    public static function message(mixed $value): string
    {
        $message = Input::text($value, 'Pesan WhatsApp', 6000);
        if (!str_contains($message, '{link_undangan}')) {
            throw new HttpException(
                422,
                'Pesan WhatsApp harus menyertakan tag {link_undangan}.',
            );
        }
        return $message;
    }
}
