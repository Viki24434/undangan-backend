<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\GuestPhotoModel;
use App\Services\Files;
use App\Services\Identifiers;
use App\Services\ImageUpload;
use App\Validation\Input;

final class GuestPhotoController
{
    public function __construct(
        private readonly GuestPhotoModel $model,
        private readonly string $directory,
    ) {}

    public function create(Request $request, object $invitation, mixed $name): array
    {
        if ($invitation->packageId !== 'platinum') {
            throw new HttpException(403, 'Kirim foto tersedia untuk paket Platinum.');
        }
        $start = strtotime($invitation->eventDate);
        if ($start === false || time() < $start) {
            throw new HttpException(403, 'Kirim foto dibuka setelah acara dimulai.');
        }
        $guest = Input::text($name, 'Nama tamu', 80);
        $normalized = \Normalizer::normalize($guest, \Normalizer::FORM_KD);
        $safe = preg_replace('/\p{M}/u', '', $normalized);
        $safe =
            substr(
                trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($safe)), '-'),
                0,
                80,
            ) ?:
            'tamu';
        $image = ImageUpload::read($request);
        $id = Identifiers::uuid();
        $now = Identifiers::now();
        $filename =
            $safe . '-' . substr($now, 0, 10) . '-' . $id . '.' . $image['extension'];
        $path = $this->directory . '/' . $filename;
        Files::write($path, $image['buffer']);
        try {
            $this->model->create(
                $id,
                $invitation->id,
                $filename,
                $image['mime'],
                strlen($image['buffer']),
                $now,
            );
        } catch (\Throwable $error) {
            Files::remove($path);
            throw $error;
        }
        return Response::data(compact('id', 'filename'), 201);
    }

    public function list(string $id, array $params): array
    {
        [$limit, $offset] = Input::pagination($params);
        return Response::data($this->model->list($id, $limit, $offset), 200, [
            'total' => $this->model->count($id),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    public function download(string $invitationId, string $id): array
    {
        $photo = $this->model->find($invitationId, $id);
        if (!$photo) {
            throw new HttpException(404, 'Foto tidak ditemukan.');
        }
        return Response::file(
            $this->directory . '/' . basename($photo['filename']),
            $photo['mime'],
            $photo['filename'],
        );
    }

    public function files(string $id): array
    {
        return $this->model->files($id);
    }

    public function clean(array $files): void
    {
        foreach ($files as $file) {
            Files::remove($this->directory . '/' . basename($file['filename']));
        }
    }
}
