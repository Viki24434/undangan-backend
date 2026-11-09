<?php

declare(strict_types=1);

namespace App\Models;

final class PackageModel extends Model
{
    public function list(): array
    {
        return $this->db->all('SELECT * FROM packages ORDER BY sort_order');
    }

    public function find(string $id): ?array
    {
        return $this->db->one('SELECT * FROM packages WHERE id=?', [$id]);
    }

    public function update(string $id, ?int $price): void
    {
        $this->db->run('UPDATE packages SET price=? WHERE id=?', [$price, $id]);
    }
}
