<?php

declare(strict_types=1);

namespace App\Validation;

use App\Core\HttpException;
use App\Core\Json;
use App\Services\ImageUpload;

final class SubmissionInput
{
    public static function map(mixed $value, callable $replace, int $depth = 0): mixed
    {
        if ($depth > 15) {
            throw new HttpException(422, 'Struktur data terlalu dalam.');
        }
        if (is_string($value)) {
            return $replace($value);
        }
        if (is_array($value)) {
            return array_map(
                static fn($item) => self::map($item, $replace, $depth + 1),
                $value,
            );
        }
        if ($value instanceof \stdClass) {
            $result = new \stdClass();
            foreach ($value as $key => $item) {
                if (in_array($key, ['__proto__', 'prototype', 'constructor'], true)) {
                    throw new HttpException(422, 'Nama field tidak valid.');
                }
                $result->$key = self::map($item, $replace, $depth + 1);
            }
            return $result;
        }
        return $value;
    }

    public static function forValidation(object $data): object
    {
        return self::map(
            $data,
            static fn($value) => str_starts_with($value, 'customer-image:')
                ? 'https://submission.invalid/' . substr($value, 15) . '.jpg'
                : $value,
        );
    }

    public static function validate(object $body): array
    {
        $id = Input::text($body->id ?? null, 'ID kiriman', 36);
        $token = Input::text($body->token ?? null, 'Token kiriman', 64);
        if (
            !preg_match('/^[a-f0-9-]{36}$/D', $id) ||
            !preg_match('/^[a-f0-9]{64}$/D', $token)
        ) {
            throw new HttpException(422, 'Identitas kiriman tidak valid.');
        }
        $contactInput = Input::object($body->contact ?? null, 'Kontak pengirim');
        $contact = (object) [
            'name' => Input::text($contactInput->name ?? null, 'Nama pengirim', 80),
            'phone' => Input::text(
                $contactInput->phone ?? null,
                'WhatsApp pengirim',
                30,
            ),
            'note' => Input::text(
                $contactInput->note ?? '',
                'Catatan untuk admin',
                1000,
                true,
            ),
        ];
        if (
            !preg_match('/^\+?[\d\s()-]{8,30}$/D', $contact->phone) ||
            !preg_match('/^\d{8,15}$/D', preg_replace('/\D/', '', $contact->phone))
        ) {
            throw new HttpException(422, 'Nomor WhatsApp pengirim tidak valid.');
        }
        if (!is_array($body->assets ?? null) || count($body->assets) > 80) {
            throw new HttpException(422, 'Maksimal 80 gambar per kiriman.');
        }
        $hashes = [];
        $assets = [];
        $total = 0;
        foreach ($body->assets as $asset) {
            Input::object($asset, 'Gambar kiriman');
            if (
                !is_string($asset->hash ?? null) ||
                !preg_match('/^[a-f0-9]{64}$/D', $asset->hash) ||
                isset($hashes[$asset->hash]) ||
                !isset(ImageUpload::FORMATS[$asset->mime ?? '']) ||
                !is_int($asset->size ?? null) ||
                $asset->size < 1 ||
                $asset->size > 5 * 1024 * 1024
            ) {
                throw new HttpException(422, 'Daftar gambar tidak valid.');
            }
            $hashes[$asset->hash] = true;
            $total += $asset->size;
            $assets[] = (object) [
                'hash' => $asset->hash,
                'size' => $asset->size,
                'mime' => $asset->mime,
            ];
        }
        if ($total > 40 * 1024 * 1024) {
            throw new HttpException(422, 'Total gambar maksimal 40 MB.');
        }
        $form = Input::object($body->form ?? null, 'Form undangan');
        Input::object($form->data ?? null, 'Isi undangan');
        $used = [];
        $nodes = 0;
        $cleanData = self::map($form->data, static function ($value) use (
            &$used,
            &$nodes,
            $hashes,
        ) {
            $nodes++;
            if ($nodes > 15000 || mb_strlen($value) > 10000) {
                throw new HttpException(422, 'Isi undangan terlalu besar.');
            }
            if (preg_match('/^(data|blob|javascript|vbscript):/i', $value)) {
                throw new HttpException(
                    422,
                    'Gambar lokal harus dikirim melalui unggahan gambar.',
                );
            }
            if (str_starts_with($value, 'customer-image:')) {
                $hash = substr($value, 15);
                if (!isset($hashes[$hash])) {
                    throw new HttpException(422, 'Referensi gambar tidak valid.');
                }
                $used[$hash] = true;
            }
            return $value;
        });
        if (count($used) !== count($hashes)) {
            throw new HttpException(
                422,
                'Ada gambar yang tidak digunakan dalam undangan.',
            );
        }
        $validated = InvitationInput::validate(
            Json::merge(
                $form,
                (object) [
                    'data' => self::forValidation($cleanData),
                    'slug' => 'customer-submission',
                    'status' => 'draft',
                ],
            ),
        );
        $guestInput = Input::object($body->guestList ?? null, 'Daftar tamu');
        $guestList = (object) [
            'guests' => GuestListInput::validate(
                (object) ['guests' => $guestInput->guests ?? null, 'revision' => 0],
            ),
            'messageTemplate' => GuestListInput::message(
                $guestInput->messageTemplate ?? null,
            ),
            'invitationUrl' => Input::text(
                $guestInput->invitationUrl ?? '',
                'Link referensi customer',
                2000,
                true,
            ),
        ];
        if (strlen(Json::encode($guestList)) > 2097152) {
            throw new HttpException(413, 'Daftar tamu maksimal 2 MB.');
        }
        return [
            $id,
            $token,
            (object) [
                'contact' => $contact,
                'form' => (object) [
                    'templateId' => $validated->templateId,
                    'title' => $validated->title,
                    'eventDate' => $validated->eventDate,
                    'data' => $cleanData,
                ],
                'guestList' => $guestList,
                'assets' => $assets,
            ],
        ];
    }
}
