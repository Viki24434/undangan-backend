<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Response;
use App\Models\GuestListModel;
use App\Services\Identifiers;
use App\Validation\GuestListInput;

final class GuestListController
{
    public function __construct(private readonly GuestListModel $model) {}

    private function invitation(string $id): array
    {
        $row = $this->model->invitation($id);
        if (!$row) {
            throw new HttpException(404, 'Undangan tidak ditemukan.');
        }
        return $row;
    }

    public function find(string $id): array
    {
        return Response::data(
            ['invitation' => $this->invitation($id)] +
                GuestListModel::record($this->model->find($id)),
        );
    }

    public function update(string $id, object $body): array
    {
        $this->invitation($id);
        $guests = GuestListInput::validate($body);
        $message = property_exists($body, 'messageTemplate')
            ? GuestListInput::message($body->messageTemplate)
            : GuestListModel::record($this->model->find($id))['messageTemplate'];
        $now = Identifiers::now();
        if (!$this->model->save($id, $guests, $body->revision, $now, $message)) {
            throw new HttpException(
                409,
                'Daftar tamu atau pesan berubah di tab lain. Cadangkan melalui ekspor Excel, lalu muat ulang sebelum menerapkan perubahan kembali.',
            );
        }
        return Response::data([
            'revision' => $body->revision + 1,
            'updatedAt' => $now,
            'messageTemplate' => $message,
        ]);
    }
}
