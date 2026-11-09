<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Json;

final class GuestListModel extends Model
{
    public static function record(?array $row): array
    {
        $document = $row ? Json::decode($row['data']) : new \stdClass();
        return [
            'guests' => is_array($document) ? $document : $document->guests ?? [],
            'messageTemplate' => is_array($document)
                ? ''
                : $document->messageTemplate ?? '',
            'revision' => (int) ($row['revision'] ?? 0),
            'updatedAt' => $row['updated_at'] ?? null,
        ];
    }

    public function invitation(string $id): ?array
    {
        return $this->db->one(
            'SELECT id, title, slug, status FROM invitations WHERE id=?',
            [$id],
        );
    }

    public function find(string $id): ?array
    {
        return $this->db->one(
            'SELECT * FROM invitation_guest_lists WHERE invitation_id=?',
            [$id],
        );
    }

    public function save(
        string $id,
        array $guests,
        int $revision,
        string $now,
        string $message,
    ): bool {
        $data = Json::encode(
            (object) ['guests' => $guests, 'messageTemplate' => $message],
        );
        if ($revision === 0) {
            return $this->db->run(
                'INSERT IGNORE INTO invitation_guest_lists (invitation_id, data, revision, updated_at) VALUES (?, ?, 1, ?)',
                [$id, $data, $now],
            ) === 1;
        }
        return $this->db->run(
            'UPDATE invitation_guest_lists SET data=?, revision=revision+1, updated_at=? WHERE invitation_id=? AND revision=?',
            [$data, $now, $id, $revision],
        ) === 1;
    }
}
