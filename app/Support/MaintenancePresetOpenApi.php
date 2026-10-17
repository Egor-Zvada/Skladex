<?php

namespace App\Support;

final class MaintenancePresetOpenApi
{
    public static function operations(): array
    {
        return [
            'api.maintenance.presets.index' => ['Рекомендуемые регламенты', null, 'MaintenancePresetPage', 200, 'read', 'Каталог проверочных списков и до 60 подходящих предметов выбранной группы. Интервалы — начальные рекомендации Skladex; требования конкретной модели уточняются отдельно. В списке нет архивного или списанного имущества.'],
            'api.maintenance.presets.apply' => ['Добавить рекомендуемые регламенты', 'MaintenancePresetApply', 'MaintenancePresetApplyResponse', 200, 'write', 'От 1 до 500 UUID с актуальными версиями. Все версии и категории проверяются до изменения. Предметы с активным планом и ранее применённым шаблоном пропускаются; настройки сохраняются. Без first_due_on дата неизвестна, напоминания и работы не создаются. Выполнение обслуживания и состояние имущества не меняются.'],
        ];
    }

    public static function schemas(): array
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $uuid = ['type' => 'string', 'format' => 'uuid'];
        $key = ['type' => 'string', 'enum' => array_keys(config('maintenance-presets'))];
        $object = fn (array $properties, array $required) => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => true];
        $ref = fn (string $name) => ['$ref' => '#/components/schemas/'.$name];
        $template = $object([
            'name' => ['type' => 'string'], 'scope' => ['type' => 'string'],
            'interval_unit' => ['type' => 'string', 'enum' => ['day', 'week', 'month', 'year']], 'interval_count' => $id,
            'lead_days' => ['type' => 'integer', 'minimum' => 0],
            'checklist_schema' => ['type' => 'array', 'items' => $ref('ChecklistStep')],
            'sources' => ['anyOf' => [['type' => 'object', 'additionalProperties' => ['type' => 'string', 'format' => 'uri']], ['type' => 'array', 'maxItems' => 0]]],
        ], ['name', 'scope', 'interval_unit', 'interval_count', 'lead_days', 'checklist_schema', 'sources']);

        return [
            'MaintenancePreset' => $template,
            'MaintenancePresetApply' => $object([
                'preset_key' => $key,
                'asset_uuids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 500, 'uniqueItems' => true, 'items' => $uuid],
                'asset_versions' => ['type' => 'object', 'minProperties' => 1, 'maxProperties' => 500, 'propertyNames' => $uuid, 'additionalProperties' => $id],
                'first_due_on' => ['type' => ['string', 'null'], 'format' => 'date'],
                'interval_unit' => ['type' => 'string', 'enum' => ['day', 'week', 'month', 'year']],
                'interval_count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 120],
            ], ['preset_key', 'asset_uuids', 'asset_versions']),
            'MaintenancePresetApplyResponse' => $object(['data' => $object([
                'created' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 500],
                'skipped' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 500],
                'plans' => ['type' => 'array', 'maxItems' => 500, 'items' => $uuid], 'preset_key' => $key,
            ], ['created', 'skipped', 'plans', 'preset_key'])], ['data']),
            'MaintenancePresetPage' => $object([
                'catalog' => ['type' => 'object', 'propertyNames' => $key, 'additionalProperties' => $ref('MaintenancePreset')],
                'selected' => $key,
                'data' => ['type' => 'array', 'maxItems' => 60, 'items' => $object([
                    'uuid' => $uuid, 'code' => ['type' => 'string'], 'name' => ['type' => 'string'], 'version' => $id,
                    'category_id' => ['type' => ['integer', 'null'], 'minimum' => 1], 'has_active_plan' => ['type' => 'boolean'],
                ], ['uuid', 'code', 'name', 'version', 'category_id', 'has_active_plan'])],
                'meta' => $object(['page' => $id, 'last_page' => $id, 'total' => ['type' => 'integer', 'minimum' => 0]], ['page', 'last_page', 'total']),
            ], ['catalog', 'selected', 'data', 'meta']),
        ];
    }
}
