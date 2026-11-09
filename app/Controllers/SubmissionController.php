<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Json;
use App\Core\Request;
use App\Core\Response;
use App\Models\GuestListModel;
use App\Models\InvitationModel;
use App\Models\SubmissionModel;
use App\Models\TemplateModel;
use App\Services\Files;
use App\Services\Identifiers;
use App\Services\ImageUpload;
use App\Validation\Input;
use App\Validation\InvitationInput;
use App\Validation\SubmissionInput;

final class SubmissionController
{
    public function __construct(
        private readonly SubmissionModel $model,
        private readonly TemplateModel $templates,
        private readonly string $directory,
        private readonly string $mediaDirectory,
    ) {}

    private function find(string $id): array
    {
        if (!preg_match('/^[a-f0-9-]{36}$/D', $id)) {
            throw new HttpException(404, 'Kiriman tidak ditemukan.');
        }
        $row = $this->model->find($id);
        if (!$row) {
            throw new HttpException(404, 'Kiriman tidak ditemukan.');
        }
        return $row;
    }

    private function receipt(array $row): array
    {
        return [
            'id' => $row['id'],
            'reference' => strtoupper($row['id']),
            'status' => $row['status'],
            'createdAt' => $row['created_at'],
        ];
    }

    private function authorized(Request $request, string $id): array
    {
        $row = $this->find($id);
        $token = $request->bearer();
        if (
            !preg_match('/^[a-f0-9]{64}$/D', $token) ||
            !hash_equals($row['token_hash'], hash('sha256', $token))
        ) {
            throw new HttpException(403, 'Token kiriman tidak valid.');
        }
        if (
            $row['status'] === 'uploading' &&
            time() - strtotime($row['created_at']) > 86400
        ) {
            throw new HttpException(
                410,
                'Sesi pengiriman kedaluwarsa. Silakan kirim kembali.',
            );
        }
        return $row;
    }

    private function file(string $id, object $asset): string
    {
        return $this->directory .
            '/' .
            $id .
            '/' .
            $asset->hash .
            '.' .
            ImageUpload::FORMATS[$asset->mime];
    }

    private function asset(array $row, string $hash): object
    {
        foreach (Json::object($row['data'])->assets as $asset) {
            if ($asset->hash === $hash) {
                return $asset;
            }
        }
        throw new HttpException(404, 'Gambar tidak terdaftar.');
    }

    private function cleanup(): void
    {
        $cutoff = (new \DateTimeImmutable('-24 hours'))->format('Y-m-d\TH:i:s.v\Z');
        foreach ($this->model->expired($cutoff) as $row) {
            if ($this->model->removeExpired($row['id'], $cutoff)) {
                Files::cleanSubmission($this->directory, $row['id']);
            }
        }
    }

    public function create(object $body): array
    {
        [$id, $token, $data] = SubmissionInput::validate($body);
        if (!$this->templates->find($data->form->templateId)) {
            throw new HttpException(422, 'Template tidak tersedia.');
        }
        $cutoff = (new \DateTimeImmutable('-24 hours'))->format('Y-m-d\TH:i:s.v\Z');
        if ($this->model->removeExpired($id, $cutoff)) {
            Files::cleanSubmission($this->directory, $id);
        }
        $this->cleanup();
        $payloadHash = hash('sha256', Json::encode($data));
        $this->model->create(
            $id,
            hash('sha256', $token),
            $payloadHash,
            $data,
            Identifiers::now(),
        );
        $row = $this->find($id);
        if (!hash_equals($row['token_hash'], hash('sha256', $token))) {
            throw new HttpException(
                409,
                'Identitas kiriman sudah digunakan untuk data berbeda.',
            );
        }
        if (!hash_equals($row['payload_hash'], $payloadHash)) {
            $oldData = Json::object($row['data']);
            if (Json::encode($oldData) !== Json::encode($data)) {
                throw new HttpException(
                    409,
                    'Identitas kiriman sudah digunakan untuk data berbeda.',
                );
            }
        }
        return Response::data($this->receipt($row), 201);
    }

    public function status(Request $request, string $id): array
    {
        $row = $this->authorized($request, $id);
        $uploaded = [];
        if ($row['status'] === 'uploading') {
            foreach (Json::object($row['data'])->assets as $asset) {
                $path = $this->file($id, $asset);
                if (is_file($path) && filesize($path) === $asset->size) {
                    $uploaded[] = $asset->hash;
                }
            }
        }
        return Response::data($this->receipt($row) + ['uploaded' => $uploaded]);
    }

    public function image(Request $request, string $id, string $hash): array
    {
        $row = $this->authorized($request, $id);
        if ($row['status'] !== 'uploading') {
            throw new HttpException(409, 'Kiriman sudah selesai diproses.');
        }
        $asset = $this->asset($row, $hash);
        $image = ImageUpload::read($request);
        if (
            strlen($image['buffer']) !== $asset->size ||
            $image['mime'] !== $asset->mime ||
            !hash_equals($hash, hash('sha256', $image['buffer']))
        ) {
            throw new HttpException(422, 'Isi gambar tidak sesuai dengan kiriman.');
        }
        $this->model->locked($id, function ($current) use ($id, $asset, $image, $hash) {
            if ($current['status'] !== 'uploading') {
                throw new HttpException(409, 'Kiriman sudah selesai diproses.');
            }
            $filename = $this->file($id, $asset);
            if (
                is_file($filename) &&
                hash_equals($hash, hash_file('sha256', $filename))
            ) {
                return;
            }
            $temporary = $filename . '.' . Identifiers::uuid() . '.tmp';
            Files::write($temporary, $image['buffer']);
            try {
                if (!rename($temporary, $filename)) {
                    throw new \RuntimeException('Gambar belum selesai disimpan.');
                }
            } finally {
                Files::remove($temporary);
            }
        });
        return Response::data(['hash' => $hash]);
    }

    public function complete(Request $request, string $id): array
    {
        $this->authorized($request, $id);
        $this->model->locked($id, function ($row, Database $db) use ($id) {
            if ($row['status'] !== 'uploading') {
                return;
            }
            foreach (Json::object($row['data'])->assets as $asset) {
                $path = $this->file($id, $asset);
                if (
                    !is_file($path) ||
                    filesize($path) !== $asset->size ||
                    !hash_equals($asset->hash, hash_file('sha256', $path))
                ) {
                    throw new HttpException(
                        422,
                        'Masih ada gambar yang belum terkirim. Coba kirim kembali.',
                    );
                }
            }
            $db->run(
                "UPDATE customer_submissions SET status='pending', updated_at=? WHERE id=?",
                [Identifiers::now(), $id],
            );
        });
        return Response::data($this->receipt($this->find($id)));
    }

    public function list(array $params): array
    {
        [$limit, $offset] = Input::pagination($params);
        $status = $params['status'] ?? '';
        if (
            $status !== '' &&
            !in_array($status, ['pending', 'accepted', 'rejected'], true)
        ) {
            throw new HttpException(422, 'Filter status tidak valid.');
        }
        return Response::data($this->model->list($status, $limit, $offset), 200, [
            'total' => $this->model->count($status),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    public function detail(string $id): array
    {
        $row = $this->find($id);
        $payload = Json::object($row['data']);
        $template = $this->templates->find($payload->form->templateId);
        $payload->form->rendererId =
            $template['renderer_id'] ?? null ?: $payload->form->templateId;
        return Response::data(
            $this->receipt($row) +
                get_object_vars($payload) + [
                    'templateName' => $template['name'] ?? $payload->form->templateId,
                    'invitationId' => $row['invitation_id'],
                    'reviewNote' => $row['review_note'],
                ],
        );
    }

    public function downloadImage(string $id, string $hash): array
    {
        $asset = $this->asset($this->find($id), $hash);
        return Response::file($this->file($id, $asset), $asset->mime, null, true);
    }

    public function accept(string $id, object $body): array
    {
        $copied = [];
        try {
            $result = $this->model->locked($id, function ($row, Database $db) use (
                $id,
                $body,
                &$copied,
            ) {
                if ($row['status'] === 'accepted') {
                    if (!$row['invitation_id']) {
                        throw new HttpException(
                            409,
                            'Draft dari kiriman ini sudah dihapus.',
                        );
                    }
                    return ['invitationId' => $row['invitation_id']];
                }
                if ($row['status'] !== 'pending') {
                    throw new HttpException(
                        409,
                        'Kiriman ini tidak sedang menunggu tinjauan.',
                    );
                }
                $data = Json::object($row['data']);
                $validation = InvitationInput::validate(
                    Json::merge(
                        $data->form,
                        (object) [
                            'data' => SubmissionInput::forValidation($data->form->data),
                            'slug' => $body->slug ?? null,
                            'status' => 'draft',
                        ],
                    ),
                );
                $invitations = new InvitationModel($db);
                if ($invitations->findSlug($validation->slug)) {
                    throw new HttpException(
                        409,
                        'Tautan undangan sudah digunakan. Pilih tautan lain.',
                    );
                }
                $urls = [];
                Files::directory($this->mediaDirectory);
                foreach ($data->assets as $asset) {
                    $filename =
                        Identifiers::uuid() . '.' . ImageUpload::FORMATS[$asset->mime];
                    $target = $this->mediaDirectory . '/' . $filename;
                    if (!copy($this->file($id, $asset), $target)) {
                        throw new \RuntimeException(
                            'Gambar kiriman tidak dapat disalin.',
                        );
                    }
                    $copied[] = $target;
                    $urls['customer-image:' . $asset->hash] = '/api/media/' . $filename;
                }
                $data->form->data = SubmissionInput::map(
                    $data->form->data,
                    static fn($value) => $urls[$value] ?? $value,
                );
                $input = InvitationInput::validate(
                    Json::merge(
                        $data->form,
                        (object) ['slug' => $body->slug, 'status' => 'draft'],
                    ),
                );
                $template = $this->templates->find($input->templateId);
                if (!$template) {
                    throw new HttpException(422, 'Template tidak tersedia.');
                }
                $defaults = Json::object($template['data']);
                $input->data->theme ??= $defaults->theme ?? new \stdClass();
                $input->data->design ??=
                    $defaults->design ??
                    (object) [
                        'version' => 1,
                        'sections' => new \stdClass(),
                        'native' => new \stdClass(),
                    ];
                $invitationId = Identifiers::uuid();
                $now = Identifiers::now();
                $invitations->create($invitationId, $input, $now);
                (new GuestListModel($db))->save(
                    $invitationId,
                    $data->guestList->guests,
                    0,
                    $now,
                    $data->guestList->messageTemplate,
                );
                $db->run(
                    "UPDATE customer_submissions SET status='accepted', invitation_id=?, data=?, updated_at=? WHERE id=?",
                    [$invitationId, Json::encode($data), $now, $id],
                );
                return ['invitationId' => $invitationId];
            });
        } catch (\Throwable $error) {
            foreach ($copied as $file) {
                Files::remove($file);
            }
            throw $error;
        }
        try {
            Files::cleanSubmission($this->directory, $id);
        } catch (\Throwable $error) {
            error_log($error->getMessage());
        }
        return Response::data($result);
    }

    public function reject(string $id, object $body): array
    {
        $note = Input::text($body->note ?? '', 'Catatan admin', 1000, true);
        $this->model->locked($id, static function ($row, Database $db) use (
            $id,
            $note,
        ) {
            if (!in_array($row['status'], ['pending', 'rejected'], true)) {
                throw new HttpException(409, 'Status kiriman tidak dapat diubah.');
            }
            $db->run(
                "UPDATE customer_submissions SET status='rejected', review_note=?, updated_at=? WHERE id=?",
                [$note, Identifiers::now(), $id],
            );
        });
        return Response::data(['status' => 'rejected']);
    }

    public function remove(string $id): array
    {
        $this->model->locked(
            $id,
            static fn($row, Database $db) => $db->run(
                'DELETE FROM customer_submissions WHERE id=?',
                [$id],
            ),
        );
        Files::cleanSubmission($this->directory, $id);
        return Response::data([
            'message' => 'Kiriman dihapus. Undangan yang telah dibuat tetap tersimpan.',
        ]);
    }
}
