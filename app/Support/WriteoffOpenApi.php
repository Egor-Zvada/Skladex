<?php

namespace App\Support;

final class WriteoffOpenApi
{
    public static function operations(): array
    {
        return [
            'api.writeoffs.index' => ['Реестр списанного оборудования', null, 'WriteoffPage', 200, 'read', 'Только written_off, 30 на странице. Автоматическое удаление отключено; основной реестр эти предметы не содержит.'],
            'api.writeoffs.show' => ['Списание и история решений', null, 'WriteoffDetailResponse', 200, 'read', 'Только written_off; другие статусы — 404. purge_blockers объясняет сохранение связанных исторических записей. Личные заметки, снимки и путь резервной копии не возвращаются.'],
            'api.writeoffs.store' => ['Списать оборудование', 'WriteoffCommand', 'WriteoffResponse', 200, 'write', 'Явная причина и текущая версия обязательны. Размещение, состав, комплект, активные лицензии/установки ПО, инвентаризация и незавершённые работы/заявки блокируют действие. Активные планы приостанавливаются с сохранением прежнего состояния. Снимок и переход статуса сохраняются атомарно.'],
            'api.writeoffs.restore' => ['Вернуть предмет из списания', 'WriteoffRestoreCommand', 'WriteoffResponse', 200, 'write', 'Явно задайте stock/in_use/repair и location_id (null снимает помещение). Возврат не включает регламенты и не назначает лицензии автоматически. Архивные записи не восстанавливаются.'],
            'api.writeoffs.purge' => ['Полностью удалить списанный предмет', 'WriteoffPurgeCommand', 'OkResponse', 200, 'purge-assets', 'Только администратор с read, write и purge-assets. Требуются версия, причина и точное совпадение confirmation_code с кодом предмета. Связанная операционная история блокирует удаление; неиспользованные приостановленные планы можно удалить со снимком. До удаления обязательна закрытая зашифрованная резервная копия; история перемещений переносится в аудит. Старые код, UUID и QR резервируются навсегда, подготовленные документы сохраняют свои снимки. Повтор с тем же Idempotency-Key возвращает успешный ответ и после удаления строки.'],
        ];
    }

    public static function schemas(): array
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $nullableId = ['type' => ['integer', 'null'], 'minimum' => 1];
        $version = ['expected_version' => $id];
        $reason = ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000];
        $ref = fn (string $name) => ['$ref' => '#/components/schemas/'.$name];
        $object = fn (array $properties, array $required) => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => true];
        $properties = [
            'id' => $id, 'uuid' => ['type' => 'string', 'format' => 'uuid'], 'code' => ['type' => 'string', 'maxLength' => 64],
            'name' => ['type' => 'string', 'maxLength' => 255], 'category_id' => $id, 'location_id' => $nullableId,
            'inventory_no' => ['type' => ['string', 'null'], 'maxLength' => 120], 'serial_no' => ['type' => ['string', 'null'], 'maxLength' => 160],
            'status' => ['type' => 'string', 'enum' => ['stock', 'in_use', 'repair', 'written_off']],
            'condition' => ['type' => 'string', 'enum' => ['working', 'limited', 'faulty', 'unknown']], 'version' => $id,
        ];
        $history = $object([
            'uuid' => ['type' => 'string', 'format' => 'uuid'], 'reason' => ['type' => 'string'],
            'written_off_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            'restored_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
        ], ['uuid', 'reason', 'written_off_at', 'restored_at']);

        return [
            'WriteoffItem' => $object($properties, array_keys($properties)),
            'WriteoffResponse' => $object(['data' => $ref('WriteoffItem')], ['data']),
            'WriteoffPage' => $object(['data' => ['type' => 'array', 'items' => $ref('WriteoffItem')], 'meta' => $object(['page' => $id, 'last_page' => $id, 'total' => ['type' => 'integer', 'minimum' => 0]], ['page', 'last_page', 'total'])], ['data', 'meta']),
            'WriteoffDetailResponse' => $object(['data' => ['allOf' => [$ref('WriteoffItem'), $object(['purge_blockers' => ['type' => 'array', 'items' => ['type' => 'string']], 'history' => ['type' => 'array', 'items' => $history]], ['purge_blockers', 'history'])]]], ['data']),
            'WriteoffCommand' => $object($version + ['reason' => $reason], ['expected_version', 'reason']),
            'WriteoffRestoreCommand' => $object($version + ['reason' => $reason, 'status' => ['type' => 'string', 'enum' => ['stock', 'in_use', 'repair']], 'location_id' => $nullableId], ['expected_version', 'reason', 'status', 'location_id']),
            'WriteoffPurgeCommand' => $object($version + ['reason' => $reason, 'confirmation_code' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'description' => 'Точный код из списанной карточки, с учётом регистра.']], ['expected_version', 'reason', 'confirmation_code']),
        ];
    }
}
