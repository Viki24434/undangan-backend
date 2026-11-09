<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Identifiers;

final class AdminModel extends Model
{
    public function find(): ?array
    {
        return $this->db->one("SELECT * FROM admins WHERE id='primary-admin'");
    }

    public function create(string $name, string $email, string $hash): void
    {
        $this->db->run(
            'INSERT INTO admins (id, name, email, password_hash) VALUES (?, ?, ?, ?)',
            ['primary-admin', $name, $email, $hash],
        );
    }

    public function updatePassword(string $hash): void
    {
        $this->db->run("UPDATE admins SET password_hash=? WHERE id='primary-admin'", [
            $hash,
        ]);
    }

    public function session(string $hash): ?array
    {
        return $this->db->one(
            'SELECT s.*, a.name, a.email FROM admin_sessions s JOIN admins a ON a.id=s.admin_id WHERE token_hash=? AND expires_at>?',
            [$hash, Identifiers::milliseconds()],
        );
    }

    public function createSession(string $hash, string $csrfToken, int $expires): void
    {
        $this->db->run(
            'INSERT INTO admin_sessions (token_hash, admin_id, csrf_token, expires_at) VALUES (?, ?, ?, ?)',
            [$hash, 'primary-admin', $csrfToken, $expires],
        );
    }

    public function removeSession(string $hash): void
    {
        $this->db->run('DELETE FROM admin_sessions WHERE token_hash=?', [$hash]);
    }

    public function cleanup(): void
    {
        $this->db->run('DELETE FROM admin_sessions WHERE expires_at<=?', [
            Identifiers::milliseconds(),
        ]);
    }
}
