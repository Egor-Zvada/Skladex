<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

class Version
{
    public static function check(Model $model, mixed $expected): void
    {
        abort_unless(filter_var($expected, FILTER_VALIDATE_INT) !== false && (int) $expected === (int) $model->version, 409, 'Запись изменилась. Обновите страницу и проверьте актуальные данные.');
    }
}
