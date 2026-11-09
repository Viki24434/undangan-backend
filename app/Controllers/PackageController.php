<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Response;
use App\Models\PackageModel;

final class PackageController
{
    public function __construct(private readonly PackageModel $model) {}

    private function record(array $row): array
    {
        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'price' => $row['price'] === null ? null : (int) $row['price'],
            'priceMode' => $row['id'] === 'exclusive' ? 'custom' : 'fixed',
            'currency' => 'IDR',
        ];
    }

    public function list(): array
    {
        return Response::data(array_map($this->record(...), $this->model->list()));
    }

    public function update(string $id, object $body): array
    {
        if (!$this->model->find($id)) {
            throw new HttpException(404, 'Paket tidak ditemukan.');
        }
        if ($id === 'exclusive') {
            throw new HttpException(422, 'Harga Exclusive ditentukan secara custom.');
        }
        if (
            !property_exists($body, 'price') ||
            ($body->price !== null &&
                (!is_int($body->price) ||
                    $body->price < 0 ||
                    $body->price > 1000000000000))
        ) {
            throw new HttpException(
                422,
                'Harga harus berupa rupiah bulat antara 0 dan 1.000.000.000.000.',
            );
        }
        $this->model->update($id, $body->price);
        return Response::data($this->record($this->model->find($id)));
    }
}
