<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Response;
use App\Models\SocialLinksModel;
use App\Validation\Input;

final class SocialLinksController
{
    public function __construct(private readonly SocialLinksModel $model) {}

    public function get(): array
    {
        return Response::data($this->model->find());
    }

    public function update(object $body): array
    {
        $result = new \stdClass();
        foreach (['instagram', 'tiktok'] as $field) {
            $value = Input::text($body->$field ?? null, $field, 500, true);
            if (!property_exists($body, $field)) {
                throw new HttpException(422, "{$field} harus berupa URL HTTPS.");
            }
            if ($value !== '') {
                $url = parse_url($value);
                if (
                    !filter_var($value, FILTER_VALIDATE_URL) ||
                    ($url['scheme'] ?? '') !== 'https' ||
                    !in_array(
                        strtolower($url['host'] ?? ''),
                        [$field . '.com', 'www.' . $field . '.com'],
                        true,
                    ) ||
                    trim($url['path'] ?? '', '/') === '' ||
                    isset($url['user']) ||
                    isset($url['pass'])
                ) {
                    throw new HttpException(
                        422,
                        "{$field} harus berupa tautan profil HTTPS yang valid.",
                    );
                }
            }
            $result->$field = $value;
        }
        $whatsapp = $body->whatsapp ?? null;
        if (
            !is_string($whatsapp) ||
            ($whatsapp !== '' && !preg_match('/^[1-9]\d{7,14}$/D', $whatsapp))
        ) {
            throw new HttpException(
                422,
                'Nomor WhatsApp harus berupa 8–15 digit dengan kode negara tanpa tanda +.',
            );
        }
        $result->whatsapp = $whatsapp;
        $this->model->update($result);
        return $this->get();
    }
}
