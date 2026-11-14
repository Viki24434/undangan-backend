<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\HttpException;
use App\Core\Json;
use App\Services\Identifiers;

final class TemplateBackupModel extends Model
{
    public function records(array $ids): \Generator
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $size = $this->db->one(
            "SELECT COALESCE(SUM(OCTET_LENGTH(data)), 0) AS bytes FROM templates WHERE id IN ({$placeholders})",
            $ids,
        );
        if ((int) $size['bytes'] > 15728640) {
            throw new HttpException(
                413,
                'Data tema melebihi 15 MB. Ekspor lebih sedikit tema.',
            );
        }
        $statement = $this->db->query(
            "SELECT * FROM templates WHERE id IN ({$placeholders}) ORDER BY id",
            $ids,
        );
        $count = 0;
        while ($row = $statement->fetch()) {
            $count++;
            yield $row;
        }
        if ($count !== count($ids)) {
            throw new HttpException(
                404,
                'Sebagian template tidak ditemukan. Muat ulang koleksi.',
            );
        }
    }

    public function existing(array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return array_column(
            $this->db->all(
                "SELECT id FROM templates WHERE id IN ({$placeholders})",
                $ids,
            ),
            'id',
        );
    }

    public function restore(array $templates, string $mode): array
    {
        usort($templates, static fn($left, $right) => strcmp($left->id, $right->id));
        return $this->db->transaction(function () use ($templates, $mode) {
            $result = ['created' => 0, 'updated' => 0, 'copied' => 0, 'ids' => []];
            foreach ($templates as $template) {
                $existing = $this->db->one(
                    'SELECT id FROM templates WHERE id=? FOR UPDATE',
                    [$template->id],
                );
                $copy = $existing && $mode === 'copy';
                $id = $copy ? 'custom-' . Identifiers::uuid() : $template->id;
                $values = [
                    $template->name,
                    $template->category,
                    $template->description,
                    $template->img,
                    Json::encode($template->data),
                    $template->packageId,
                    $template->rendererId,
                    $copy || $template->isCustom,
                    $id,
                ];
                if ($existing && !$copy) {
                    $this->db->run(
                        'UPDATE templates SET name=?, category=?, description=?, image=?, data=?, package_id=?, renderer_id=?, is_custom=? WHERE id=?',
                        $values,
                    );
                    $result['updated']++;
                } else {
                    $this->db->run(
                        'INSERT INTO templates (name, category, description, image, data, package_id, renderer_id, is_custom, id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        $values,
                    );
                    $result['created']++;
                    $result['copied'] += (int) $copy;
                }
                $result['ids'][] = $id;
            }
            return $result;
        });
    }
}
