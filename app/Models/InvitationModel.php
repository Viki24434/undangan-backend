<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Json;
use App\Services\Identifiers;

final class InvitationModel extends Model
{
    public static function record(array $row): object
    {
        return (object) [
            'id' => $row['id'],
            'slug' => $row['slug'],
            'templateId' => $row['template_id'],
            'title' => $row['title'],
            'eventDate' => $row['event_date'],
            'data' => Json::object($row['data']),
            'status' => $row['status'],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
        ];
    }

    public function find(string $id): ?array
    {
        return $this->db->one('SELECT * FROM invitations WHERE id=?', [$id]);
    }

    public function published(string $slug): ?array
    {
        return $this->db->one(
            "SELECT * FROM invitations WHERE slug=? AND status='published'",
            [$slug],
        );
    }

    public function findSlug(string $slug, ?string $exceptId = null): ?array
    {
        return $exceptId
            ? $this->db->one('SELECT id FROM invitations WHERE slug=? AND id<>?', [
                $slug,
                $exceptId,
            ])
            : $this->db->one('SELECT id FROM invitations WHERE slug=?', [$slug]);
    }

    public function list(int $limit, int $offset): array
    {
        return $this->db->all(
            'SELECT * FROM invitations ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?',
            [$limit, $offset],
        );
    }

    public function count(): int
    {
        return (int) $this->db->one('SELECT COUNT(*) AS total FROM invitations')[
            'total'
        ];
    }

    public function create(string $id, object $input, string $now): void
    {
        $this->db->run(
            'INSERT INTO invitations (id, slug, template_id, title, event_date, data, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id,
                $input->slug,
                $input->templateId,
                $input->title,
                $input->eventDate,
                Json::encode($input->data),
                $input->status,
                $now,
                $now,
            ],
        );
    }

    public function update(string $id, object $input): void
    {
        $this->db->run(
            'UPDATE invitations SET slug=?, template_id=?, title=?, event_date=?, data=?, status=?, updated_at=? WHERE id=?',
            [
                $input->slug,
                $input->templateId,
                $input->title,
                $input->eventDate,
                Json::encode($input->data),
                $input->status,
                Identifiers::now(),
                $id,
            ],
        );
    }

    public function remove(string $id): void
    {
        $this->db->run('DELETE FROM invitations WHERE id=?', [$id]);
    }
}
