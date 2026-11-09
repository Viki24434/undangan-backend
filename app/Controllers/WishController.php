<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Response;
use App\Models\WishModel;
use App\Services\Identifiers;
use App\Validation\Input;

final class WishController
{
    public function __construct(private readonly WishModel $model) {}

    public function list(string $id, array $params): array
    {
        [$limit, $offset] = Input::pagination($params);
        return Response::data($this->model->list($id, $limit, $offset), 200, [
            'total' => $this->model->count($id),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    public function create(string $invitationId, object $body): array
    {
        $name = Input::text($body->name ?? null, 'Nama', 80);
        $message = Input::text($body->message ?? null, 'Ucapan', 600);
        [$wish, $updated] = $this->model->save(
            Identifiers::uuid(),
            $invitationId,
            hash('sha256', Identifiers::normalizedName($name)),
            $name,
            $message,
            Identifiers::now(),
        );
        return Response::data($wish, $updated ? 200 : 201, ['updated' => $updated]);
    }

    public function remove(string $id): array
    {
        if (!$this->model->remove($id)) {
            throw new HttpException(404, 'Ucapan tidak ditemukan.');
        }
        return Response::data(['message' => 'Ucapan telah dihapus.']);
    }
}
