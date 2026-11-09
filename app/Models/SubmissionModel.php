<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\HttpException;
use App\Core\Json;

final class SubmissionModel extends Model
{
    public function find(string $id): ?array
    {
        return $this->db->one('SELECT * FROM customer_submissions WHERE id=?', [$id]);
    }

    public function create(
        string $id,
        string $tokenHash,
        string $payloadHash,
        object $data,
        string $now,
    ): void {
        $this->db->run(
            "INSERT IGNORE INTO customer_submissions (id, token_hash, payload_hash, data, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'uploading', ?, ?)",
            [$id, $tokenHash, $payloadHash, Json::encode($data), $now, $now],
        );
    }

    public function list(string $status, int $limit, int $offset): array
    {
        $filter = $status ? 'status=?' : "status<>'uploading'";
        return $this->db->all(
            "SELECT id, status, invitation_id, review_note, created_at, updated_at,
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.contact.name')) AS customer_name,
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.form.title')) AS title,
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.form.templateId')) AS template_id,
            JSON_LENGTH(JSON_EXTRACT(data, '$.guestList.guests')) AS guest_count
            FROM customer_submissions WHERE {$filter} ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?",
            $status ? [$status, $limit, $offset] : [$limit, $offset],
        );
    }

    public function count(string $status): int
    {
        $filter = $status ? 'status=?' : "status<>'uploading'";
        return (int) $this->db->one(
            "SELECT COUNT(*) AS total FROM customer_submissions WHERE {$filter}",
            $status ? [$status] : [],
        )['total'];
    }

    public function expired(string $cutoff): array
    {
        return $this->db->all(
            "SELECT id FROM customer_submissions WHERE status='uploading' AND created_at<? LIMIT 20",
            [$cutoff],
        );
    }

    public function removeExpired(string $id, string $cutoff): int
    {
        return $this->db->run(
            "DELETE FROM customer_submissions WHERE id=? AND status='uploading' AND created_at<?",
            [$id, $cutoff],
        );
    }

    public function locked(string $id, callable $work): mixed
    {
        return $this->db->transaction(function () use ($id, $work) {
            $row = $this->db->one(
                'SELECT * FROM customer_submissions WHERE id=? FOR UPDATE',
                [$id],
            );
            if (!$row) {
                throw new HttpException(404, 'Kiriman tidak ditemukan.');
            }
            return $work($row, $this->db);
        });
    }
}
