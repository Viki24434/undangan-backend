<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Response;
use App\Models\RsvpModel;
use App\Services\Identifiers;
use App\Validation\Input;

final class RsvpController
{
    public function __construct(private readonly RsvpModel $model) {}

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
        $attendance = $body->attendance ?? null;
        if (!in_array($attendance, ['Hadir', 'Belum pasti', 'Tidak hadir'], true)) {
            throw new HttpException(
                422,
                'attendance harus Hadir, Belum pasti, atau Tidak hadir.',
            );
        }
        $count = $body->guestCount ?? ($attendance === 'Tidak hadir' ? 0 : 1);
        if (
            !is_int($count) ||
            $count < 0 ||
            $count > 20 ||
            ($attendance === 'Hadir' && $count < 1) ||
            ($attendance === 'Tidak hadir' && $count !== 0)
        ) {
            throw new HttpException(
                422,
                'guestCount tidak sesuai kehadiran atau di luar 0–20.',
            );
        }
        $note = Input::text($body->note ?? null, 'Catatan', 600, true);
        $id = Identifiers::format(
            hash('sha256', $invitationId . ':' . Identifiers::normalizedName($name)),
        );
        $this->model->save(
            $id,
            $invitationId,
            $name,
            $attendance,
            $count,
            $note,
            Identifiers::now(),
        );
        return Response::data(
            ['id' => $id, 'message' => 'Konfirmasi kehadiran berhasil disimpan.'],
            201,
        );
    }
}
