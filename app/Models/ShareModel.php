<?php

declare(strict_types=1);

namespace App\Models;

final class ShareModel extends Model
{
    public function find(string $value, bool $byId = false): ?array
    {
        $field = $byId ? 'i.id' : 'i.slug';
        return $this->db->one(
            "SELECT i.id, i.slug, i.title, i.event_date, i.status, i.updated_at,
            JSON_UNQUOTE(JSON_EXTRACT(i.data, '$.image')) AS image,
            JSON_UNQUOTE(JSON_EXTRACT(t.data, '$.image')) AS template_image,
            JSON_UNQUOTE(JSON_EXTRACT(i.data, '$.presentation.kind')) AS kind,
            t.category, t.image AS catalog_image
            FROM invitations i JOIN templates t ON t.id=i.template_id WHERE {$field}=?",
            [$value],
        );
    }
}
