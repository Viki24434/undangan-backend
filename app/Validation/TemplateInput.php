<?php

declare(strict_types=1);

namespace App\Validation;

use App\Core\HttpException;
use App\Core\Json;

final class TemplateInput
{
    public static function data(mixed $input): object
    {
        $data = clone Input::object($input, 'Data template');
        if (strlen(Json::encode($data)) > 500000) {
            throw new HttpException(422, 'Data template terlalu besar.');
        }
        if (property_exists($data, 'design')) {
            $data->design = DesignInput::validate($data->design);
        }
        $data->theme = ThemeInput::validate($data->theme ?? new \stdClass());
        if (property_exists($data, 'presentation')) {
            $data->presentation = PresentationInput::validate($data->presentation);
        }
        return $data;
    }
}
