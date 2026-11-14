<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Json;
use App\Core\Request;
use App\Core\Response;
use App\Models\TemplateBackupModel;
use App\Services\Files;
use App\Services\ImageUpload;
use App\Validation\Input;
use App\Validation\TemplateBackupInput;

final class TemplateBackupController
{
    public function __construct(
        private readonly TemplateBackupModel $model,
        private readonly string $media,
    ) {}

    public function export(object $body): array
    {
        $ids = $body->ids ?? null;
        if (
            !is_array($ids) ||
            count($ids) < 1 ||
            count($ids) > TemplateBackupInput::MAX_TEMPLATES
        ) {
            throw new HttpException(422, 'Pilih 1–500 template untuk diekspor.');
        }
        $ids = array_values(
            array_unique(array_map(TemplateBackupInput::id(...), $ids)),
        );
        $templates = [];
        $bytes = 0;
        foreach ($this->model->records($ids) as $row) {
            $bytes += strlen($row['data'] ?? '') + 4096;
            if ($bytes > 15728640) {
                throw new HttpException(
                    413,
                    'Data tema melebihi 15 MB. Ekspor lebih sedikit tema.',
                );
            }
            $templates[] = (object) [
                'id' => $row['id'],
                'name' => $row['name'],
                'category' => $row['category'],
                'description' => $row['description'],
                'img' => $row['image'],
                'packageId' => $row['package_id'],
                'rendererId' => $row['renderer_id'] ?: $row['id'],
                'isCustom' => (bool) $row['is_custom'],
                'data' => Json::object($row['data']),
            ];
        }
        $assets = [];
        foreach (TemplateBackupInput::references($templates) as $source) {
            $filename = basename($source);
            $file = $this->media . '/' . $filename;
            if (!is_file($file) || is_link($file)) {
                throw new HttpException(
                    422,
                    "Gambar {$filename} tidak ditemukan. Perbaiki gambar tema sebelum ekspor.",
                );
            }
            $assets[] = (object) [
                'source' => $source,
                'path' => 'uploads/' . $filename,
                'size' => filesize($file),
                'sha256' => hash_file('sha256', $file),
            ];
        }
        $manifest = (object) [
            'format' => TemplateBackupInput::FORMAT,
            'version' => 1,
            'exportedAt' => gmdate('c'),
            'templates' => $templates,
            'assets' => $assets,
        ];
        TemplateBackupInput::read($manifest);
        return Response::data($manifest);
    }

    public function inspect(object $body): array
    {
        $backup = TemplateBackupInput::read($body->manifest ?? null);
        $existing = $this->model->existing(
            array_map(static fn($item) => $item->id, $backup->templates),
        );
        return Response::data([
            'templates' => array_map(
                static fn($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'packageId' => $item->packageId,
                    'exists' => in_array($item->id, $existing, true),
                ],
                $backup->templates,
            ),
            'assets' => count($backup->assets),
            'existing' => count($existing),
        ]);
    }

    public function image(Request $request, string $hash): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $hash)) {
            throw new HttpException(422, 'Sidik gambar cadangan tidak valid.');
        }
        $image = ImageUpload::read($request);
        if (!hash_equals($hash, hash('sha256', $image['buffer']))) {
            throw new HttpException(422, 'Gambar cadangan rusak atau telah berubah.');
        }
        $key = substr($hash, 0, 32);
        $filename =
            substr($key, 0, 8) .
            '-' .
            substr($key, 8, 4) .
            '-' .
            substr($key, 12, 4) .
            '-' .
            substr($key, 16, 4) .
            '-' .
            substr($key, 20) .
            '.' .
            $image['extension'];
        $file = $this->media . '/' . $filename;
        if (!is_file($file)) {
            try {
                Files::write($file, $image['buffer']);
            } catch (\Throwable $error) {
                if (
                    !is_file($file) ||
                    !hash_equals($hash, hash_file('sha256', $file))
                ) {
                    throw $error;
                }
            }
        }
        if (is_link($file) || !hash_equals($hash, hash_file('sha256', $file))) {
            throw new HttpException(
                409,
                'Nama gambar cadangan sudah digunakan oleh file lain.',
            );
        }
        return Response::data(['url' => '/api/media/' . $filename], 201);
    }

    public function restore(object $body): array
    {
        $backup = TemplateBackupInput::read($body->manifest ?? null);
        $mode = $body->mode ?? null;
        if (!in_array($mode, ['copy', 'merge'], true)) {
            throw new HttpException(422, 'Pilih cara pemulihan tema.');
        }
        $replacements = Input::object($body->replacements ?? null, 'Pemetaan gambar');
        if (count(get_object_vars($replacements)) !== count($backup->assets)) {
            throw new HttpException(
                422,
                'Gambar belum selesai diunggah. Coba impor kembali.',
            );
        }
        foreach ($backup->assets as $asset) {
            $url = $replacements->{$asset->source} ?? null;
            if (
                !is_string($url) ||
                !preg_match(TemplateBackupInput::MEDIA_PATTERN, $url)
            ) {
                throw new HttpException(422, 'Pemetaan gambar cadangan tidak valid.');
            }
            $file = $this->media . '/' . basename($url);
            if (
                !is_file($file) ||
                is_link($file) ||
                filesize($file) !== $asset->size ||
                pathinfo($url, PATHINFO_EXTENSION) !==
                    pathinfo($asset->source, PATHINFO_EXTENSION) ||
                !hash_equals($asset->sha256, hash_file('sha256', $file))
            ) {
                throw new HttpException(
                    422,
                    'Gambar cadangan belum lengkap atau tidak cocok.',
                );
            }
        }
        $rewrite = static function ($value) use (&$rewrite, $replacements) {
            if (is_string($value)) {
                return $replacements->$value ?? $value;
            }
            if (is_array($value)) {
                return array_map($rewrite, $value);
            }
            if (is_object($value)) {
                foreach ($value as $key => $child) {
                    $value->$key = $rewrite($child);
                }
            }
            return $value;
        };
        $templates = $rewrite($backup->templates);
        return Response::data($this->model->restore($templates, $mode), 201);
    }
}
