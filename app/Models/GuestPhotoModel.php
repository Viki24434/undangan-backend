<?php

declare(strict_types=1);

namespace App\Models;

final class GuestPhotoModel extends Model
{
    public function create(
        string $id,
        string $invitationId,
        string $filename,
        string $mime,
        int $size,
        string $now,
    ): void {
        $this->db->run(
            'INSERT INTO guest_photos (id, invitation_id, filename, mime, size, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $invitationId, $filename, $mime, $size, $now],
        );
    }

    public function list(string $id, int $limit, int $offset): array
    {
        return $this->db->all(
            'SELECT id, filename, size, created_at AS createdAt FROM guest_photos WHERE invitation_id=? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?',
            [$id, $limit, $offset],
        );
    }

    public function count(string $id): int
    {
        return (int) $this->db->one(
            'SELECT COUNT(*) AS total FROM guest_photos WHERE invitation_id=?',
            [$id],
        )['total'];
    }

    public function find(string $invitationId, string $id): ?array
    {
        return $this->db->one(
            'SELECT * FROM guest_photos WHERE invitation_id=? AND id=?',
            [$invitationId, $id],
        );
    }

    public function files(string $id): array
    {
        return $this->db->all(
            'SELECT filename FROM guest_photos WHERE invitation_id=?',
            [$id],
        );
    }
}
