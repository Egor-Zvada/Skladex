<?php

namespace App\Support;

final class GeneratedDocumentOpenApi
{
    public static function operations(): array
    {
        return [
            'api.document-records.index' => ['Документы по оборудованию', null, 'GeneratedDocumentPage', 200, 'read', 'Приватный список, 25 документов на странице. Фильтры q, status=draft|final, template_key. Встроенные формы предварительные; не утверждённые бланки организации.'],
            'api.document-records.store' => ['Создать черновик документа', 'GeneratedDocumentCreate', 'GeneratedDocumentResponse', 201, 'write', 'asset_ids — от 1 до 100 различных UUID имущества в желаемом порядке; expected_assets — карта UUID→актуальная версия. Реквизиты каждого предмета и версия шаблона сохраняются снимком. Статусы и местонахождение предметов не меняются.'],
            'api.document-records.show' => ['Документ и сохранённый перечень', null, 'GeneratedDocumentResponse', 200, 'read', 'Включает приватный текст и снимки серийных/инвентарных номеров. Документ остаётся доступен после удаления карточек оборудования. Не публикуйте ответ на гостевой странице.'],
            'api.document-records.update' => ['Изменить текст черновика', 'GeneratedDocumentUpdate', 'GeneratedDocumentResponse', 200, 'write', 'Только draft, актуальная expected_version обязательна. Состав оборудования и версия шаблона остаются прежними. Для другого перечня создайте документ заново.'],
            'api.document-records.finalize' => ['Зафиксировать редакцию', 'ExpectedVersion', 'GeneratedDocumentResponse', 200, 'write', 'Блокирует дальнейшее редактирование текста. Фиксация не является подписью или согласованием и не выполняет передачу, ремонт либо списание. Повтор с прежним Idempotency-Key возвращает исходный результат.'],
            'api.document-records.pdf' => ['Скачать PDF документа', null, 'PDFDocument', 200, 'read', 'Приватный PDF A4 из сохранённой редакции и снимков оборудования. Без Idempotency-Key. Генерация локальная; пользовательский текст экранируется, сеть/PHP/JavaScript отключены.'],
        ];
    }

    public static function schemas(): array
    {
        $key = ['type' => 'string', 'enum' => ['transfer', 'defect', 'inspection', 'writeoff', 'memo', 'equipment_list']];
        $uuid = ['type' => 'string', 'format' => 'uuid'];
        $version = ['type' => 'integer', 'minimum' => 1];
        $date = ['type' => 'string', 'format' => 'date', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'];
        $datetime = ['type' => 'string', 'format' => 'date-time'];
        $fields = ['title' => self::text(255), 'document_date' => $date, 'body' => self::text(12000), 'organization' => self::text(255, true), 'author_name' => self::text(255, true), 'recipient_name' => self::text(255, true)];
        $requiredFields = ['title', 'document_date', 'body'];
        $template = self::object(['key' => $key, 'version' => $version, 'name' => self::text(255), 'introduction' => self::text(2000), 'signature' => self::text(2000), 'notice' => self::text(2000)], ['key', 'version', 'name', 'introduction', 'signature', 'notice']);
        $snapshot = [
            'uuid' => $uuid, 'code' => self::text(64), 'name' => self::text(255),
            'inventory_no' => self::text(255, true), 'serial_no' => self::text(255, true),
            'manufacturer' => self::text(255, true), 'model' => self::text(255, true),
            'location_uuid' => ['type' => ['string', 'null'], 'format' => 'uuid'], 'location_code' => self::text(64, true),
            'location_name' => self::text(255, true), 'building_name' => self::text(255, true),
            'status' => ['type' => 'string', 'enum' => ['stock', 'in_use', 'repair', 'written_off']], 'asset_version' => $version,
        ];
        $summary = [
            'uuid' => $uuid, 'number' => self::text(64), 'template_key' => $key, 'template_version' => $version,
            'status' => ['type' => 'string', 'enum' => ['draft', 'final']], 'title' => self::text(255),
            'version' => $version, 'document_date' => $date, 'created_at' => $datetime,
            'finalized_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
        ];
        $item = self::object(['position' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'snapshot' => self::object($snapshot, array_keys($snapshot))], ['position', 'snapshot']);
        $detail = $summary + $fields + ['template_snapshot' => self::ref('GeneratedDocumentTemplate'), 'items' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => $item]];
        $s = [];
        $s['GeneratedDocumentTemplate'] = $template;
        $s['GeneratedDocumentCreate'] = self::object($fields + [
            'template_key' => $key,
            'asset_ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'uniqueItems' => true, 'items' => $uuid, 'description' => 'Различные UUID имущества; регистр не учитывается. Порядок сохраняется в документе.'],
            'expected_assets' => ['type' => 'object', 'minProperties' => 1, 'maxProperties' => 500, 'propertyNames' => $uuid, 'additionalProperties' => $version, 'description' => 'Карта UUID→version для каждого выбранного предмета. Несовпадение версии любого предмета откатывает всю команду.'],
        ], array_merge($requiredFields, ['template_key', 'asset_ids', 'expected_assets']));
        $s['GeneratedDocumentUpdate'] = self::object($fields + ['expected_version' => $version], array_merge($requiredFields, ['expected_version']));
        $s['GeneratedDocumentDetail'] = self::object($detail, array_keys($detail));
        $s['GeneratedDocumentResponse'] = self::object(['data' => self::ref('GeneratedDocumentDetail')], ['data']);
        $s['GeneratedDocumentSummary'] = self::object($summary + ['items_count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]], array_merge(array_keys($summary), ['items_count']));
        $s['GeneratedDocumentPage'] = self::object([
            'data' => ['type' => 'array', 'items' => self::ref('GeneratedDocumentSummary'), 'maxItems' => 25],
            'meta' => self::object(['page' => $version, 'last_page' => $version, 'total' => ['type' => 'integer', 'minimum' => 0]], ['page', 'last_page', 'total']),
            'templates' => ['type' => 'object', 'propertyNames' => $key, 'additionalProperties' => self::ref('GeneratedDocumentTemplate')],
        ], ['data', 'meta', 'templates']);

        return $s;
    }

    private static function object(array $properties, array $required): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => true];
    }

    private static function text(int $max, bool $nullable = false): array
    {
        return ['type' => $nullable ? ['string', 'null'] : 'string', 'maxLength' => $max] + ($nullable ? [] : ['minLength' => 1, 'pattern' => '\\S']);
    }

    private static function ref(string $name): array
    {
        return ['$ref' => '#/components/schemas/'.$name];
    }
}
