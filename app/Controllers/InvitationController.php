<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Json;
use App\Core\Response;
use App\Models\InvitationModel;
use App\Models\TemplateModel;
use App\Services\Identifiers;
use App\Validation\Input;
use App\Validation\InvitationInput;

final class InvitationController
{
    public function __construct(
        private readonly InvitationModel $model,
        private readonly TemplateModel $templates,
        private readonly GuestPhotoController $photos,
    ) {}

    private function enriched(array $row): object
    {
        $invitation = InvitationModel::record($row);
        $template = $this->templates->find($invitation->templateId);
        $defaults = Json::object($template['data'] ?? null);
        $overrides = $invitation->data;
        $data = Json::merge(
            $defaults,
            (object) ['banks' => [], 'wishes' => []],
            $overrides,
        );
        $data->design =
            $overrides->design ??
            (object) [
                'version' => 1,
                'sections' => new \stdClass(),
                'native' => new \stdClass(),
            ];
        $data->agenda = $overrides->agenda ?? ($defaults->agenda ?? []);
        $data->videoUrl = $overrides->videoUrl ?? ($defaults->videoUrl ?? '');
        $data->prayers = $overrides->prayers ?? ($defaults->prayers ?? []);
        $data->events =
            $overrides->events ??
            array_map(static function ($event) {
                $event = clone $event;
                unset($event->date);
                return $event;
            }, $defaults->events ?? []);
        foreach (['groom', 'bride'] as $key) {
            if (isset($defaults->$key)) {
                $data->$key = Json::merge(
                    $defaults->$key,
                    $overrides->$key ?? new \stdClass(),
                );
            }
        }
        $invitation->data = $data;
        $invitation->rendererId = $template['renderer_id'] ?: $invitation->templateId;
        $invitation->packageId = $template['package_id'] ?? null;
        return $invitation;
    }

    public function find(string $id): object
    {
        $row = $this->model->find($id);
        if (!$row) {
            throw new HttpException(404, 'Undangan tidak ditemukan.');
        }
        return $this->enriched($row);
    }

    public function published(string $slug): object
    {
        $row = $this->model->published($slug);
        if (!$row) {
            throw new HttpException(404, 'Undangan tidak ditemukan.');
        }
        return $this->enriched($row);
    }

    public function list(array $params): array
    {
        [$limit, $offset] = Input::pagination($params);
        return Response::data(
            array_map(
                InvitationModel::record(...),
                $this->model->list($limit, $offset),
            ),
            200,
            ['total' => $this->model->count(), 'limit' => $limit, 'offset' => $offset],
        );
    }

    private function validate(object $input, ?string $exceptId = null): array
    {
        $template = $this->templates->find($input->templateId);
        if (!$template) {
            throw new HttpException(422, 'Template tidak tersedia.');
        }
        if ($this->model->findSlug($input->slug, $exceptId)) {
            throw new HttpException(409, 'slug sudah digunakan.');
        }
        return $template;
    }

    public function create(object $body): array
    {
        $input = InvitationInput::validate($body);
        $template = $this->validate($input);
        $defaults = Json::object($template['data']);
        $input->data->theme ??= $defaults->theme ?? new \stdClass();
        $input->data->design ??=
            $defaults->design ??
            (object) [
                'version' => 1,
                'sections' => new \stdClass(),
                'native' => new \stdClass(),
            ];
        $id = Identifiers::uuid();
        $this->model->create($id, $input, Identifiers::now());
        return Response::data($this->find($id), 201);
    }

    public function update(string $id, object $body): array
    {
        $current = $this->find($id);
        $input = InvitationInput::validate($body, $current);
        $input->data->design ??= $current->data->design;
        $input->data->theme ??= $current->data->theme ?? new \stdClass();
        $this->validate($input, $id);
        $this->model->update($id, $input);
        return Response::data($this->find($id));
    }

    public function remove(string $id): array
    {
        $this->find($id);
        $files = $this->photos->files($id);
        $this->model->remove($id);
        $this->photos->clean($files);
        return Response::data([
            'message' => 'Undangan dan data tamu terkait telah dihapus.',
        ]);
    }
}
