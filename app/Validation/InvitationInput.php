<?php

declare(strict_types=1);

namespace App\Validation;

use App\Core\HttpException;
use App\Core\Json;

final class InvitationInput
{
    public static function validate(object $body, ?object $current = null): \stdClass
    {
        $value = Json::merge($current ?? new \stdClass(), $body);
        $slug = Input::text($value->slug ?? null, 'slug', 80);
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) {
            throw new HttpException(
                422,
                'slug hanya boleh berisi huruf kecil, angka, dan tanda hubung.',
            );
        }
        $eventDate = Input::text($value->eventDate ?? null, 'eventDate', 40);
        if (
            !preg_match(
                '/^(\d{4})-(\d{2})-(\d{2})T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/D',
                $eventDate,
                $date,
            ) ||
            !checkdate((int) $date[2], (int) $date[3], (int) $date[1])
        ) {
            throw new HttpException(
                422,
                'eventDate harus berupa ISO 8601 dengan tanggal dan zona waktu yang valid.',
            );
        }
        try {
            $parsed = new \DateTimeImmutable($eventDate);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors && ($errors['warning_count'] || $errors['error_count'])) {
                throw new \Exception();
            }
        } catch (\Exception) {
            throw new HttpException(422, 'Tanggal acara tidak valid.');
        }
        $data = clone Input::object($value->data ?? new \stdClass(), 'data');
        $data->sunlightEnabled ??= true;
        if (!is_bool($data->sunlightEnabled)) {
            throw new HttpException(422, 'sunlightEnabled harus berupa boolean.');
        }
        $photos = $data->photos ?? [];
        if (!is_array($photos) || count($photos) > 30) {
            throw new HttpException(422, 'Maksimal 30 foto per undangan.');
        }
        $data->photos = array_map(Input::photo(...), $photos);
        $agenda = $data->agenda ?? [];
        if (!is_array($agenda) || count($agenda) > 30) {
            throw new HttpException(422, 'Maksimal 30 kegiatan agenda.');
        }
        $data->agenda = array_map(static function ($item): object {
            Input::object($item, 'Agenda');
            return (object) [
                'time' => Input::text($item->time ?? null, 'Waktu agenda', 60),
                'title' => Input::text($item->title ?? null, 'Nama agenda', 160),
                'description' => Input::text(
                    $item->description ?? '',
                    'Keterangan agenda',
                    1000,
                    true,
                ),
            ];
        }, $agenda);
        $prayers = $data->prayers ?? [];
        if (!is_array($prayers) || count($prayers) > 20) {
            throw new HttpException(422, 'Maksimal 20 doa.');
        }
        $data->prayers = array_map(static function ($item): object {
            Input::object($item, 'Doa');
            return (object) [
                'title' => Input::text($item->title ?? null, 'Judul doa', 160),
                'text' => Input::text($item->text ?? null, 'Isi doa', 3000),
                'arabic' => Input::text(
                    $item->arabic ?? '',
                    'Teks Arab doa',
                    3000,
                    true,
                ),
                'source' => Input::text($item->source ?? '', 'Sumber doa', 300, true),
            ];
        }, $prayers);
        $data->videoUrl = self::video($data->videoUrl ?? '');
        if (property_exists($data, 'design')) {
            $data->design = DesignInput::validate($data->design);
        }
        if (property_exists($data, 'theme')) {
            $data->theme = ThemeInput::validate($data->theme);
        }
        $status = $value->status ?? 'draft';
        if (!in_array($status, ['draft', 'published'], true)) {
            throw new HttpException(422, 'status harus draft atau published.');
        }
        return (object) [
            'slug' => $slug,
            'templateId' => Input::text($value->templateId ?? null, 'templateId', 80),
            'title' => Input::text($value->title ?? null, 'title', 160),
            'eventDate' => $eventDate,
            'data' => $data,
            'status' => $status,
        ];
    }

    private static function video(mixed $value): string
    {
        $value = Input::text($value, 'videoUrl', 1000, true);
        if ($value === '') {
            return '';
        }
        $url = parse_url($value);
        if (
            !filter_var($value, FILTER_VALIDATE_URL) ||
            ($url['scheme'] ?? '') !== 'https'
        ) {
            throw new HttpException(422, 'videoUrl harus berupa URL HTTPS.');
        }
        $host = strtolower($url['host']);
        $path = $url['path'] ?? '';
        $parts = array_values(array_filter(explode('/', $path), 'strlen'));
        parse_str($url['query'] ?? '', $query);
        $supported = preg_match('/\.(mp4|webm|ogg)$/iD', $path);
        if (
            in_array(
                $host,
                [
                    'youtu.be',
                    'youtube.com',
                    'www.youtube.com',
                    'm.youtube.com',
                    'youtube-nocookie.com',
                    'www.youtube-nocookie.com',
                ],
                true,
            )
        ) {
            $id =
                $host === 'youtu.be'
                    ? $parts[0] ?? ''
                    : ($path === '/watch'
                        ? $query['v'] ?? ''
                        : (end($parts) ?:
                        ''));
            $supported =
                $supported || (is_string($id) && preg_match('/^[\w-]{11}$/D', $id));
        } elseif (
            in_array($host, ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], true)
        ) {
            $supported = $supported || preg_match('/^\d+$/D', end($parts) ?: '');
        }
        if (!$supported) {
            throw new HttpException(
                422,
                'Gunakan tautan YouTube, Vimeo, atau file MP4/WebM/OGG.',
            );
        }
        return $value;
    }
}
