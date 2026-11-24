<?php

declare(strict_types=1);

namespace App\Models;

final class TemplateRevisionModel extends Model
{
    public function capture(array $template, string $reason): void
    {
        $this->db->run(
            'INSERT INTO template_revisions (template_id, revision, data, reason, created_at) VALUES (?, ?, ?, ?, ?)',
            [
                $template['id'],
                $template['revision'],
                $template['data'] ?: '{}',
                $reason,
                gmdate('Y-m-d\TH:i:s\Z'),
            ],
        );
        $oldest = $this->db->one(
            'SELECT revision FROM template_revisions WHERE template_id=? ORDER BY revision DESC LIMIT 1 OFFSET 9',
            [$template['id']],
        );
        if ($oldest) {
            $this->db->run(
                'DELETE FROM template_revisions WHERE template_id=? AND revision<?',
                [$template['id'], $oldest['revision']],
            );
        }
    }

    public function list(string $id): array
    {
        return $this->db->all(
            'SELECT id, revision, reason, created_at, OCTET_LENGTH(data) AS bytes FROM template_revisions WHERE template_id=? ORDER BY revision DESC LIMIT 10',
            [$id],
        );
    }

    public function find(string $templateId, string $id): ?array
    {
        return $this->db->one(
            'SELECT data, revision FROM template_revisions WHERE template_id=? AND id=?',
            [$templateId, $id],
        );
    }
}
