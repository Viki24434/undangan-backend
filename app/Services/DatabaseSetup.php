<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Json;

final class DatabaseSetup
{
    public static function run(array $config): void
    {
        $connection = new Database($config['database'], false);
        $name = $config['database']['name'];
        $connection->pdo->exec(
            "CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        );
        $db = new Database($config['database']);
        $lockName = 'undangan-schema-' . substr(hash('sha256', $name), 0, 40);
        if (!$db->one('SELECT GET_LOCK(?, 30) AS acquired', [$lockName])['acquired']) {
            throw new \RuntimeException(
                'Setup lain sedang berjalan. Coba kembali sebentar.',
            );
        }
        try {
            $schema = file_get_contents($config['root'] . '/database/schema.sql');
            foreach (explode(';', $schema) as $statement) {
                if (trim($statement) !== '') {
                    $db->pdo->exec($statement);
                }
            }
            foreach (
                [
                    ['templates', 'data', 'LONGTEXT NULL'],
                    [
                        'templates',
                        'package_id',
                        "ENUM('silver','gold','platinum','exclusive') NULL",
                    ],
                    ['templates', 'renderer_id', 'VARCHAR(80) NULL'],
                    ['templates', 'is_custom', 'TINYINT(1) NOT NULL DEFAULT 0'],
                    [
                        'wishes',
                        'guest_key',
                        'CHAR(64) NULL, ADD UNIQUE KEY uq_wishes_guest (invitation_id, guest_key)',
                    ],
                ]
                as [$table, $column, $definition]
            ) {
                if (
                    !$db->one(
                        'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?',
                        [$name, $table, $column],
                    )
                ) {
                    $db->pdo->exec(
                        "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}",
                    );
                }
            }
            $db->pdo->exec(
                'ALTER TABLE admins MODIFY COLUMN password_hash VARCHAR(255) NOT NULL',
            );
            $db->run('INSERT IGNORE INTO social_links (id, data) VALUES (1, ?)', [
                Json::encode(
                    (object) ['instagram' => '', 'tiktok' => '', 'whatsapp' => ''],
                ),
            ]);
            foreach (
                ['Silver', 'Gold', 'Platinum', 'Exclusive']
                as $index => $package
            ) {
                $db->run(
                    'INSERT IGNORE INTO packages (id, name, price, sort_order) VALUES (?, ?, NULL, ?)',
                    [strtolower($package), $package, $index],
                );
            }
            $defaults = Json::object(
                file_get_contents(
                    $config['root'] . '/database/seeds/template-data.json',
                ),
            );
            $templates = Json::decode(
                file_get_contents($config['root'] . '/database/seeds/templates.json'),
            );
            $presentations = Json::object(
                file_get_contents(
                    $config['root'] . '/database/seeds/presentations.json',
                ),
            );
            foreach ($templates as $template) {
                $data = $defaults->{$template->id} ?? new \stdClass();
                $data->presentation ??= $presentations->{$template->id};
                $data->agenda ??= [];
                $data->videoUrl ??= '';
                $data->prayers ??= [];
                $db->run(
                    'INSERT IGNORE INTO templates (id, name, category, description, image, data, package_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [
                        $template->id,
                        $template->name,
                        $template->category,
                        $template->description,
                        $template->img,
                        Json::encode($data),
                        $template->packageId,
                    ],
                );
                $db->run(
                    'UPDATE templates SET data=COALESCE(data, ?), package_id=COALESCE(package_id, ?) WHERE id=?',
                    [Json::encode($data), $template->packageId, $template->id],
                );
            }
            foreach (
                $db->all(
                    "SELECT id, renderer_id FROM templates WHERE JSON_EXTRACT(data, '$.presentation') IS NULL",
                )
                as $row
            ) {
                $sourceId = $row['renderer_id'] ?: $row['id'];
                if (isset($presentations->$sourceId)) {
                    $db->run(
                        "UPDATE templates SET data=JSON_SET(COALESCE(data, '{}'), '$.presentation', JSON_EXTRACT(?, '$')) WHERE id=? AND JSON_EXTRACT(data, '$.presentation') IS NULL",
                        [Json::encode($presentations->$sourceId), $row['id']],
                    );
                }
            }
            $db->run(
                "UPDATE invitations i JOIN templates t ON t.id=i.template_id SET i.data=JSON_SET(i.data, '$.presentation', JSON_EXTRACT(t.data, '$.presentation')) WHERE JSON_EXTRACT(i.data, '$.presentation') IS NULL AND JSON_EXTRACT(t.data, '$.presentation') IS NOT NULL",
            );
        } finally {
            $db->one('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }
}
