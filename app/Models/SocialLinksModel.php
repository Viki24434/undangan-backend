<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Json;

final class SocialLinksModel extends Model
{
    public function find(): object
    {
        $row = $this->db->one('SELECT data FROM social_links WHERE id=1');
        if (!$row) {
            throw new \RuntimeException(
                'Pengaturan sosial belum diinisialisasi. Jalankan php bin/setup.php.',
            );
        }
        return Json::object($row['data']);
    }

    public function update(object $data): void
    {
        $this->db->run('UPDATE social_links SET data=? WHERE id=1', [
            Json::encode($data),
        ]);
    }
}
