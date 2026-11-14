<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Json;
use App\Core\Response;
use App\Models\TemplateModel;
use App\Services\Identifiers;
use App\Validation\Input;
use App\Validation\TemplateInput;

final class TemplateController
{
    public function __construct(private readonly TemplateModel $model) {}

    private function record(array $row): array
    {
        $presentation = array_key_exists('presentation', $row)
            ? Json::object($row['presentation'])
            : Json::object($row['data'])->presentation ?? new \stdClass();
        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'category' => $row['category'],
            'packageId' => $row['package_id'],
            'description' => $row['description'],
            'img' => $row['image'],
            'rendererId' => $row['renderer_id'] ?: $row['id'],
            'isCustom' => (bool) $row['is_custom'],
            'presentation' => $presentation,
            'palette' => $presentation->palette ?? [],
        ];
    }

    public function find(string $id): array
    {
        $row = $this->model->find($id);
        if (!$row) {
            throw new HttpException(404, 'Template tidak ditemukan.');
        }
        return Response::data(
            $this->record($row) + ['data' => Json::object($row['data'])],
        );
    }

    public function list(?string $category): array
    {
        return Response::data(
            array_map(
                fn($row) => $this->record($row) + [
                    'theme' => Json::object($row['theme']),
                ],
                $this->model->list($category),
            ),
        );
    }

    private function package(mixed $value): string
    {
        if (!in_array($value, Input::PACKAGES, true)) {
            throw new HttpException(422, 'Paket template tidak valid.');
        }
        return $value;
    }

    public function update(string $id, object $body): array
    {
        $current = $this->find($id)['data'];
        if (!property_exists($body, 'packageId') && !property_exists($body, 'data')) {
            throw new HttpException(422, 'Tidak ada perubahan template yang dikirim.');
        }
        $package = property_exists($body, 'packageId')
            ? $this->package($body->packageId)
            : null;
        $data = property_exists($body, 'data')
            ? TemplateInput::data($body->data)
            : null;
        if ($data !== null && !isset($data->presentation)) {
            $data->presentation = $current['data']->presentation;
        }
        $this->model->update($id, $package, $data);
        return $this->find($id);
    }

    public function create(object $body): array
    {
        $base = $this->model->find(
            Input::text($body->baseTemplateId ?? null, 'Template dasar', 80),
        );
        if (!$base) {
            throw new HttpException(422, 'Template dasar tidak ditemukan.');
        }
        $baseData = Json::object($base['data']);
        $overrides = property_exists($body, 'data')
            ? TemplateInput::data($body->data)
            : new \stdClass();
        $data = Json::merge($baseData, $overrides);
        $data->theme = Json::merge(
            $baseData->theme ?? new \stdClass(),
            $overrides->theme ?? new \stdClass(),
        );
        foreach (['groom', 'bride'] as $key) {
            if (isset($baseData->$key)) {
                $data->$key = Json::merge(
                    $baseData->$key,
                    $overrides->$key ?? new \stdClass(),
                );
            }
        }
        $id = 'custom-' . Identifiers::uuid();
        $this->model->create(
            (object) [
                'id' => $id,
                'name' => Input::text($body->name ?? null, 'Nama template', 160),
                'category' => Input::text(
                    $body->category ?? $base['category'],
                    'Kategori',
                    80,
                ),
                'packageId' => $this->package($body->packageId ?? $base['package_id']),
                'description' => Input::text(
                    $body->description ?? $base['description'],
                    'Deskripsi',
                    1000,
                    true,
                ),
                'img' => $base['image'],
                'rendererId' => $base['renderer_id'] ?: $base['id'],
                'data' => TemplateInput::data($data),
            ],
        );
        return Response::data($this->find($id)['data'], 201);
    }
}
