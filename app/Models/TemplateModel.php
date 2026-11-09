<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Json;

final class TemplateModel extends Model
{
    public function find(string $id): ?array
    {
        return $this->db->one('SELECT * FROM templates WHERE id=?', [$id]);
    }

    public function list(?string $category): array
    {
        $fields =
            "id, name, category, description, image, package_id, renderer_id, is_custom, JSON_EXTRACT(data, '$.theme') AS theme";
        return $category
            ? $this->db->all(
                "SELECT {$fields} FROM templates WHERE category=? ORDER BY name",
                [$category],
            )
            : $this->db->all("SELECT {$fields} FROM templates ORDER BY name");
    }

    public function update(string $id, ?string $packageId, ?object $data): void
    {
        $this->db->transaction(function () use ($id, $packageId, $data) {
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
