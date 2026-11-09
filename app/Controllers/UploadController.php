<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Files;
use App\Services\Identifiers;
use App\Services\ImageUpload;

final class UploadController
{
    public function __construct(private readonly string $directory) {}

    public function create(Request $request): array
    {
        $image = ImageUpload::read($request);
        $filename = Identifiers::uuid() . '.' . $image['extension'];
        Files::write($this->directory . '/' . $filename, $image['buffer']);
        return Response::data(['url' => '/api/media/' . $filename], 201);
    }

    public function image(string $filename): array
    {
        if (!preg_match('/^[a-f0-9-]{36}\.(jpg|png|webp)$/D', $filename, $match)) {
            throw new HttpException(404, 'Gambar tidak ditemukan.');
        }
        return Response::file(
            $this->directory . '/' . $filename,
            array_search($match[1], ImageUpload::FORMATS, true),
        );
    }
}
