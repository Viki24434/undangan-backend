<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Json;
use App\Core\HttpException;

final class TemplateModel extends Model
{
    public function find(string $id): ?array
    {
        return $this->db->one('SELECT * FROM templates WHERE id=?', [$id]);
    }

    public function list(?string $category): array
    {
        $fields =
            "id, name, category, description, image, package_id, renderer_id, is_custom, JSON_OBJECT('background', JSON_UNQUOTE(JSON_EXTRACT(data, '$.theme.background')), 'accent', JSON_UNQUOTE(JSON_EXTRACT(data, '$.theme.accent'))) AS theme, JSON_EXTRACT(data, '$.presentation') AS presentation";
        return $category
            ? $this->db->all(
                "SELECT {$fields} FROM templates WHERE category=? ORDER BY name",
                [$category],
            )
            : $this->db->all("SELECT {$fields} FROM templates ORDER BY name");
    }

    public function update(
        string $id,
        ?string $packageId,
        ?object $data,
        ?int $revision,
    ): array {
        return $this->db->transaction(function () use (
            $id,
            $packageId,
            $data,
            $revision,
        ) {
            $current = $this->db->one('SELECT * FROM templates WHERE id=? FOR UPDATE', [
                $id,
            ]);
            if (!$current) {
                throw new HttpException(404, 'Template tidak ditemukan.');
            }
            if ($revision !== null && $revision !== (int) $current['revision']) {
                throw new HttpException(
                    409,
                    'Template sudah diubah dari tab atau komputer lain. Muat versi server atau simpan perubahan Anda sebagai template baru.',
                );
            }
            if ($data !== null) {
                if (!isset($data->presentation)) {
                    $data->presentation =
                        Json::object($current['data'])->presentation ?? new \stdClass();
                }
                (new TemplateRevisionModel($this->db))->capture($current, 'save');
            }
            if ($packageId !== null) {
                $this->db->run('UPDATE templates SET package_id=? WHERE id=?', [
                    $packageId,
                    $id,
                ]);
            }
            if ($data !== null) {
                $this->db->run('UPDATE templates SET data=? WHERE id=?', [
                    Json::encode($data),
                    $id,
                ]);
            }
            $this->db->run('UPDATE templates SET revision=revision+1 WHERE id=?', [
                $id,
            ]);
            return $this->find($id);
        });
    }

    public function create(object $template): void
    {
        $this->db->run(
            'INSERT INTO templates (id, name, category, description, image, data, package_id, renderer_id, is_custom) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)',
            [
                $template->id,
                $template->name,
                $template->category,
                $template->description,
                $template->img,
                Json::encode($template->data),
                $template->packageId,
                $template->rendererId,
            ],
        );
    }
}
