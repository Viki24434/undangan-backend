<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Response;

final class PreviewController
{
    public function __construct(
        private readonly Database $db,
        private readonly InvitationController $invitations,
    ) {}

    public function create(string $id): array
    {
        $this->invitations->find($id);
        $token = bin2hex(random_bytes(32));
        $expires = time() + 7 * 86400;
        $this->db->run(
            'INSERT INTO invitation_previews (invitation_id, token_hash, expires_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash), expires_at=VALUES(expires_at)',
            [$id, hash('sha256', $token), $expires],
        );
        return Response::data(
            ['path' => '/preview/' . $token, 'expiresAt' => gmdate('c', $expires)],
            201,
        );
    }

    public function revoke(string $id): array
    {
        $this->invitations->find($id);
        $this->db->run('DELETE FROM invitation_previews WHERE invitation_id=?', [$id]);
        return Response::data(['message' => 'Link preview customer dinonaktifkan.']);
    }

    public function view(string $token): array
    {
        $row = preg_match('/^[a-f0-9]{64}$/D', $token)
            ? $this->db->one(
                'SELECT invitation_id FROM invitation_previews WHERE token_hash=? AND expires_at>?',
                [hash('sha256', $token), time()],
            )
            : null;
        if (!$row) {
            throw new HttpException(
                404,
                'Link preview tidak tersedia atau sudah kedaluwarsa. Hubungi admin untuk link baru.',
            );
        }
        $invitation = $this->invitations->find($row['invitation_id']);
        $invitation->preview = true;
        $invitation->data->wishes = [];
        return Response::data($invitation);
    }
}
