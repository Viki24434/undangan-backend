<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Json;
use App\Core\Response;
use App\Models\TemplateModel;
use App\Models\TemplateRevisionModel;

final class TemplateRevisionController
{
    public function __construct(
        private readonly TemplateRevisionModel $revisions,
        private readonly TemplateModel $templates,
    ) {}

    public function list(string $id): array
    {
        if (!$this->templates->find($id)) {
            throw new HttpException(404, 'Template tidak ditemukan.');
        }
        return Response::data(
            array_map(
                static fn($row) => [
                    'id' => (string) $row['id'],
                    'revision' => (int) $row['revision'],
                    'reason' => $row['reason'],
                    'createdAt' => $row['created_at'],
                    'bytes' => (int) $row['bytes'],
                ],
                $this->revisions->list($id),
            ),
        );
    }

    public function find(string $id, string $revisionId): array
    {
        $row = $this->revisions->find($id, $revisionId);
        if (!$row) {
            throw new HttpException(
                404,
                'Revisi tidak ditemukan atau sudah melewati batas riwayat.',
            );
        }
        return Response::data([
            'data' => Json::object($row['data']),
            'revision' => (int) $row['revision'],
        ]);
    }
}
