<?php

declare(strict_types=1);

namespace App\Models;

final class RsvpModel extends Model
{
    public function list(string $id, int $limit, int $offset): array
    {
        return $this->db->all(
            'SELECT id, name, attendance, guest_count AS guestCount, note, created_at AS createdAt FROM rsvps WHERE invitation_id=? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?',
            [$id, $limit, $offset],
        );
    }

    public function count(string $id): int
    {
        return (int) $this->db->one(
            'SELECT COUNT(*) AS total FROM rsvps WHERE invitation_id=?',
            [$id],
        )['total'];
    }

    public function save(
        string $id,
        string $invitationId,
        string $name,
        string $attendance,
        int $guestCount,
        string $note,
        string $now,
    ): void {
        $existing = $this->db->one(
            'SELECT id FROM rsvps WHERE invitation_id=? AND name=? ORDER BY created_at DESC, id DESC LIMIT 1',
            [$invitationId, $name],
        );
        $this->db->run(
            "INSERT INTO rsvps (id, invitation_id, name, attendance, guest_count, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name=VALUES(name), attendance=VALUES(attendance), guest_count=VALUES(guest_count), note=IF(VALUES(note)='', note, VALUES(note))",
            [
                $existing['id'] ?? $id,
                $invitationId,
                $name,
                $attendance,
                $guestCount,
                $note,
                $now,
            ],
        );
    }
}
