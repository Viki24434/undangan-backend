<?php

declare(strict_types=1);

namespace App\Models;

final class WishModel extends Model
{
    public function list(string $id, int $limit, int $offset): array
    {
        return $this->db->all(
            'SELECT id, name, message, created_at AS createdAt FROM wishes WHERE invitation_id=? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?',
            [$id, $limit, $offset],
        );
    }

    public function count(string $id): int
    {
        return (int) $this->db->one(
            'SELECT COUNT(*) AS total FROM wishes WHERE invitation_id=?',
            [$id],
        )['total'];
    }

    public function save(
        string $id,
        string $invitationId,
        string $guestKey,
        string $name,
        string $message,
        string $now,
    ): array {
        $existing = $this->db->one(
            'SELECT id FROM wishes WHERE invitation_id=? AND (guest_key=? OR (guest_key IS NULL AND name=?)) ORDER BY guest_key IS NULL, created_at DESC, id DESC LIMIT 1',
            [$invitationId, $guestKey, $name],
        );
        $changes = $this->db->run(
            'INSERT INTO wishes (id, invitation_id, name, message, created_at, guest_key) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name=VALUES(name), message=VALUES(message), guest_key=VALUES(guest_key)',
            [$existing['id'] ?? $id, $invitationId, $name, $message, $now, $guestKey],
        );
        $wish = $this->db->one(
            'SELECT id, name, message, created_at AS createdAt FROM wishes WHERE invitation_id=? AND guest_key=?',
            [$invitationId, $guestKey],
        );
        return [$wish, $existing !== null || $changes !== 1];
    }

    public function remove(string $id): int
    {
        return $this->db->run('DELETE FROM wishes WHERE id=?', [$id]);
    }
}
