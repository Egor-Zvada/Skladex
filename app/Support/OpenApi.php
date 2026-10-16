<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;
use LogicException;

/** Explicit application contract. Route discovery verifies coverage; it never guesses bodies. */
final class OpenApi
{
    public static function document(): array
    {
        $definitions = self::operations();
        $schemas = self::schemas();
        $paths = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $name = $route->getName();
            if (! isset($definitions[$name])) {
                throw new LogicException('Missing explicit OpenAPI contract: '.$name);
            }
            [$summary, $requestSchema, $responseSchema, $success, $ability, $description] = $definitions[$name];
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $readOnly = in_array($name, ['api.labels.preview', 'api.labels.pdf', 'api.labels.test'], true);
                $idempotent = ! in_array($method, ['GET', 'HEAD'], true) && ! $readOnly && $name !== 'api.licenses.reveal';
                $parameters = [];
                preg_match_all('/\{([^}]+)\}/', $route->uri(), $matches);
                foreach ($matches[1] as $parameter) {
                    $numeric = in_array($parameter, ['component', 'assignment', 'installation'], true);
                    $parameters[] = ['name' => $parameter, 'in' => 'path', 'required' => true, 'description' => $numeric ? 'Внутренний числовой ID.' : 'Стабильный публичный UUID, не числовой ID и не инвентарный номер.', 'schema' => $numeric ? self::id() : self::uuid()];
                }
                if ($idempotent) {
                    $parameters[] = ['$ref' => '#/components/parameters/IdempotencyKey'];
                }
                $parameters = array_merge($parameters, self::queryParameters($name));
                $response = ['description' => 'Успешно. '.$description, 'content' => [str_starts_with($responseSchema, 'PDF') ? 'application/pdf' : 'application/json' => ['schema' => self::ref($responseSchema)]]];
                if ($name === 'api.licenses.reveal') {
                    $response['headers']['Cache-Control'] = ['description' => 'Ключ никогда не кешируется.', 'schema' => ['type' => 'string', 'examples' => ['private, no-store']]];
                }
                $operation = [
                    'operationId' => $name, 'tags' => [explode('.', $name)[1]], 'summary' => $summary,
                    'description' => $description.' Требуется разрешение токена read'.($ability === 'read' ? '' : ', '.$ability).($ability === 'accept-work' ? ' и write (общий контур изменения)' : '').'. Права роли проверяются независимо от токена.',
                    'security' => [['BearerToken' => []]], 'x-token-abilities' => array_values(array_unique(array_merge(['read'], $ability === 'accept-work' ? ['write', 'accept-work'] : [$ability]))),
                    'x-idempotency-required' => $idempotent,
                    'x-roles' => $ability === 'reveal-license' ? ['admin'] : ($ability === 'read' ? ['admin', 'operator', 'viewer'] : ['admin', 'operator']),
                    'parameters' => $parameters,
                    'responses' => [(string) $success => $response] + self::errors(),
                ];
                if ($name === 'api.maintenance.plans.generate' || $name === 'api.labels.store') {
                    $operation['responses']['200'] = $response;
                    $operation['responses']['201'] = $response;
                }
                if ($name === 'api.licenses.key.store') {
                    $operation['x-conditional-abilities'] = [['when' => 'existing protected key is replaced', 'abilities' => ['reveal-license'], 'roles' => ['admin']]];
                }
                if ($name === 'api.maintenance.work.transition') {
                    $operation['x-conditional-abilities'] = [['when' => 'action=reject_result', 'abilities' => ['accept-work']]];
                }
                if ($requestSchema) {
                    $operation['requestBody'] = ['required' => $requestSchema !== 'LabelGeometryOverride', 'content' => ['application/json' => ['schema' => self::ref($requestSchema)]]];
                }
                $paths['/'.$route->uri()][strtolower($method)] = $operation;
            }
        }
        ksort($paths);

        return [
            'openapi' => '3.1.1', 'jsonSchemaDialect' => 'https://spec.openapis.org/oas/3.1/dialect/base',
            'info' => ['title' => 'Skladex API', 'version' => '1.0.0', 'description' => 'Внутренний API ИТ- и звукового оборудования, ПО, лицензий, обслуживания и инвентаризации. Реальный контракт текущего приложения; не гостевой QR API. Не выполняет команды на оборудовании. Неизвестные свойства запросов могут игнорироваться: отправляйте только документированные поля.'],
            'servers' => [['url' => '/', 'description' => 'Текущий сервер; все пути уже содержат /api/v1.']],
            'security' => [['BearerToken' => []]], 'paths' => $paths,
            'components' => ['securitySchemes' => ['BearerToken' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'Laravel Sanctum personal access token', 'description' => 'Authorization: Bearer <PAT>. Активный пользователь и отдельные read/write/accept-work/reveal-license abilities. Cookie сессии не заменяют PAT для API.']],
                'parameters' => ['IdempotencyKey' => ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => true, 'description' => 'Уникальный ключ команды пользователя. Повтор того же HTTP-метода, пути и JSON возвращает сохранённый успешный ответ; другой запрос с тем же ключом — 409. Не меняйте типы/структуру JSON при повторе. Не используйте ключ для разных команд. Ошибочные команды откатываются.', 'schema' => ['type' => 'string', 'minLength' => 16, 'maxLength' => 100, 'pattern' => '^[a-zA-Z0-9_.:-]{16,100}$', 'examples' => ['8917eafd-9f49-43e8-8c36-73421836c676']]]],
                'schemas' => $schemas],
        ];
    }

    private static function operations(): array
    {
        // summary, body component, response component, success status, extra ability, semantics
        return [
            'api.buildings.index' => ['Список зданий', null, 'BuildingPage', 200, 'read', 'Активные здания, 50 на странице; locations_count учитывает активные кабинеты. Приватные notes скрыты.'],
            'api.buildings.store' => ['Создать здание', 'BuildingCreate', 'BuildingResponse', 201, 'write', 'Название здания задаёт оператор; UUID назначается сервером. Кабинеты связываются отдельно через building_id.'],
            'api.buildings.show' => ['Здание и кабинеты', null, 'BuildingDetail', 200, 'read', 'До 50 активных кабинетов выбранной страницы. Архивное здание — 404.'],
            'api.buildings.update' => ['Изменить здание', 'BuildingUpdate', 'BuildingResponse', 200, 'write', 'Требуется актуальная expected_version. Адрес и заметки необязательны.'],
            'api.buildings.archive' => ['Архивировать здание', 'ExpectedVersion', 'OkResponse', 200, 'write', 'Любой активный кабинет, даже пустой, блокирует архивирование. Исторические связи сохраняются; физическое удаление не выполняется.'],
            'api.categories.index' => ['Категории оборудования', null, 'CategoryList', 200, 'read', 'Системные категории ИТ/аудио: числовые ID для создания и фильтрации активов.'],
            'api.assets.index' => ['Список имущества', null, 'AssetPage', 200, 'read', 'Активные карточки, 30 на странице.'],
            'api.assets.store' => ['Создать предмет', 'AssetCreate', 'AssetResponse', 201, 'write', 'Код SKX-A и UUID назначаются сервером; inventory_no не уникален.'],
            'api.assets.show' => ['Карточка предмета', null, 'AssetDetailResponse', 200, 'read', 'Внутренний подробный DTO с составом, размещением, содержимым, ПО и маскированными ссылками лицензий. Содержит приватные заметки; не публикуйте ответ на гостевой странице. Архивный предмет — 404.'],
            'api.assets.update' => ['Изменить карточку', 'AssetUpdate', 'AssetResponse', 201, 'write', 'location_id и status запрещены при редактировании карточки: используйте /move. Текущий контроллер возвращает 201 и при обновлении.'],
            'api.assets.move' => ['Переместить имущество', 'AssetMove', 'AssetResponse', 200, 'write', 'location_id обязателен, null явно снимает помещение. Для содержимого нужны include_children=true и expected_contents: карта всех UUID потомков к их версиям из текущей карточки. Несовпадение состава/версий — 409. Оставшиеся предметы сначала отсоединяются. Предмет с физическим родителем сначала снимается.'],
            'api.assets.components.store' => ['Добавить компонент ПК', 'ComponentCreate', 'ComponentResponse', 201, 'write', 'Компонент-описание не становится отдельным активом. child_asset_id связывает существующий актив; при serial_no, inventory_no или child_asset_id quantity=1. Один физический родитель, циклы запрещены.'],
            'api.assets.components.remove' => ['Снять компонент', 'ExpectedVersion', 'OkResponse', 200, 'write', 'expected_version относится к родительскому ПК. История компонента сохраняется.'],
            'api.assets.rack' => ['Разместить предмет в rack', 'RackPlacement', 'AssetResponse', 200, 'write', 'rack_id — ID самостоятельного актива категории rack, не location_id и не комплект.'],
            'api.assets.detach' => ['Снять предмет с rack', 'ExpectedVersion', 'OkResponse', 200, 'write', 'Компоненты ПК снимаются отдельной операцией components/remove.'],
            'api.assets.archive' => ['Архивировать карточку', 'ExpectedVersion', 'OkResponse', 200, 'write', 'Физические связи, открытые работы, активные лицензии и установки могут блокировать архивирование. Физическое удаление не выполняется.'],
            'api.locations.index' => ['Список помещений', null, 'LocationPage', 200, 'read', '50 на странице; notes скрыты моделью.'],
            'api.locations.store' => ['Создать помещение', 'LocationCreate', 'LocationResponse', 201, 'write', 'Стабильные UUID и код SKX-R генерируются сервером.'],
            'api.locations.show' => ['Помещение и имущество', null, 'LocationDetail', 200, 'read', 'Массив assets содержит до 50 активных предметов выбранной страницы.'],
            'api.locations.update' => ['Изменить помещение', 'LocationUpdate', 'LocationResponse', 200, 'write', 'is_public определяет гостевую видимость; приватные notes не публикуются.'],
            'api.kits.index' => ['Логические комплекты', null, 'KitList', 200, 'read', 'До 30 комплектов выбранной страницы, каждый с assets. Физическое размещение не меняется.'],
            'api.kits.store' => ['Создать комплект', 'KitCreate', 'KitResponse', 201, 'write', 'asset_ids — числовые ID существующих активов. Комплект не подтверждает присутствие участников при инвентаризации.'],
            'api.kits.members' => ['Изменить состав комплекта', 'KitMemberCommand', 'OkResponse', 200, 'write', 'remove=true удаляет логическую связь, false добавляет. expected_version — версия комплекта.'],
            'api.software.index' => ['Каталог ПО', null, 'SoftwarePage', 200, 'read', '30 программ на странице.'],
            'api.software.store' => ['Создать программу', 'SoftwareCreate', 'SoftwareProduct', 201, 'write', 'Стороннее ПО, своя разработка или прошивка. Договор и лицензия для создания не обязательны.'],
            'api.software.show' => ['Программа, версии и установки', null, 'SoftwareDetail', 200, 'read', 'Внутренняя карточка с repository_url, releases, installations и licenses. Никаких секретных лицензионных ключей.'],
            'api.software.update' => ['Изменить программу', 'SoftwareUpdate', 'SoftwareProduct', 200, 'write', 'Ожидается текущая версия программы. URL не должен содержать credentials.'],
            'api.software.releases.store' => ['Добавить версию программы', 'ReleaseCreate', 'SoftwareRelease', 201, 'write', 'expected_version программы; version_name уникально внутри программы. Установки автоматически не обновляются.'],
            'api.software.installations.store' => ['Учесть установку/сервис', 'InstallationCommand', 'SoftwareInstallation', 201, 'write', 'expected_version программы. Обязателен asset_id или непустая environment. Версия должна относиться к программе. ВМ/контейнер можно описать независимо от физического актива.'],
            'api.software.installations.update' => ['Уточнить фактическую установку', 'InstallationCommand', 'SoftwareInstallation', 200, 'write', 'expected_version установки. Указывайте asset_id/environment и при PATCH. Изменение версии/размещения сбрасывает last_verified_at; verified_now=true подтверждает фактическую проверку сейчас. Никакого исполнения команд.'],
            'api.licenses.index' => ['Список лицензий', null, 'LicensePage', 200, 'read', '30 на странице. Доступен только признак secret_exists; секрет и ciphertext не возвращаются.'],
            'api.licenses.show' => ['Лицензия и история мест', null, 'LicenseDetailResponse', 200, 'read', 'Активные и освобождённые привязки. Установки ПО и лицензирование учитываются независимо.'],
            'api.licenses.store' => ['Быстро создать лицензию', 'LicenseCreate', 'LicenseResponse', 201, 'write', 'Программа обязательна; ключ и ПК необязательны. Автоматический SKX-L. Создание, шифрование и первоначальная привязка атомарны. Общий volume key допустим.'],
            'api.licenses.update' => ['Изменить сведения о лицензии', 'LicenseUpdate', 'LicenseResponse', 200, 'write', 'Метаданные без замены ключа. capacity ограничивает активные привязки автоматически только для metric=device.'],
            'api.licenses.key.store' => ['Добавить/заменить защищённый ключ', 'LicenseKeyCommand', 'LicenseResponse', 200, 'write', 'Первое добавление доступно write; замена существующего ключа дополнительно требует администратора и reveal-license. Пустой ввод запрещён, удаления ключа нет. Ответ не содержит ключ.'],
            'api.licenses.assign' => ['Привязать лицензию к ПК', 'LicenseAssign', 'LicenseResponse', 200, 'write', 'Один активный экземпляр привязки license+asset. Проверка device capacity выполняется под блокировкой лицензии. Неизвестная ёмкость допустима с предупреждением.'],
            'api.licenses.release' => ['Освободить место', 'ExpectedVersion', 'LicenseResponse', 200, 'write', 'expected_version лицензии. assignment должен относиться к этой лицензии. История не удаляется.'],
            'api.licenses.reveal' => ['Открыть секретный ключ', 'ExpectedVersion', 'LicenseRevealResponse', 200, 'reveal-license', 'Только администратор. Открытие аудируется; no-store; Idempotency-Key не требуется и ответ не сохраняется middleware. Никогда не журналируйте тело ответа.'],
            'api.maintenance.work.index' => ['Журнал работ', null, 'WorkPage', 200, 'read', '50 работ на странице.'],
            'api.maintenance.work.store' => ['Создать работу', 'WorkCreate', 'WorkOrder', 201, 'write', 'Ровно один объект: asset_uuid или software_installation_id. Начальный статус open.'],
            'api.maintenance.work.completed-store' => ['Записать уже выполненную работу', 'CompletedWorkCreate', 'WorkOrder', 201, 'write', 'Создание и фиксация результата атомарны; статус completed, приёмка производится отдельно.'],
            'api.maintenance.work.show' => ['Работа и история', null, 'WorkDetail', 200, 'read', 'Содержит неизменяемые события events.'],
            'api.maintenance.work.update' => ['Изменить назначение работы', 'WorkUpdate', 'WorkOrder', 200, 'write', 'Только open/in_progress/on_hold; причина обязательна.'],
            'api.maintenance.work.transition' => ['Изменить состояние работы', 'WorkTransition', 'WorkOrder', 200, 'write', 'start:open→in_progress; hold:open/in_progress→on_hold; resume:возврат; cancel:незавершённая→cancelled; reject_result:completed→in_progress дополнительно требует accept-work. hold/cancel/reject_result требуют reason.'],
            'api.maintenance.work.complete' => ['Зафиксировать выполнение', 'WorkComplete', 'WorkOrder', 200, 'write', 'Из open/in_progress/on_hold в completed. completed_at — фактическое выполнение; время без offset трактуется Asia/Sakhalin, будущее запрещено. Ответы сверяются с checklist_schema работы.'],
            'api.maintenance.work.accept' => ['Принять выполненную работу', 'WorkAccept', 'WorkOrder', 200, 'accept-work', 'Только completed→closed. Критическую работу или requires_other_acceptor принимает другой сотрудник. Зачёт ТО опирается на completed_at, а не accepted_at; counts_for_plan=true только для связанного срока. Не изменяет исправность имущества автоматически.'],
            'api.maintenance.work.correct' => ['Исправить текст закрытого результата', 'WorkCorrection', 'WorkOrder', 200, 'accept-work', 'Только closed; прежний текст остаётся в событии. Фактическое время, приёмка и зачёт ТО не меняются.'],
            'api.maintenance.requests.index' => ['Заявки на обслуживание', null, 'MaintenanceRequestPage', 200, 'read', '50 заявок на странице.'],
            'api.maintenance.requests.store' => ['Создать заявку', 'MaintenanceRequestCreate', 'MaintenanceRequest', 201, 'write', 'Ровно один объект обслуживания.'],
            'api.maintenance.requests.triage' => ['Разобрать заявку', 'RequestTriage', 'MaintenanceRequest', 200, 'write', 'Из new/needs_info. accepted создаёт работу; merged требует work_order_uuid работы по тому же объекту.'],
            'api.maintenance.plans.index' => ['Регламенты ТО', null, 'PlanPage', 200, 'read', '50 регламентов на странице.'],
            'api.maintenance.plans.store' => ['Создать регламент', 'PlanCreate', 'MaintenancePlan', 201, 'write', 'first_due_on=null означает неизвестную исходную дату, а не выполненное обслуживание. Оборудование или установка ПО, ровно одно.'],
            'api.maintenance.plans.show' => ['Регламент и сроки', null, 'PlanDetail', 200, 'read', 'Включает occurrences с попытками work_orders.'],
            'api.maintenance.plans.update' => ['Изменить/приостановить регламент', 'PlanUpdate', 'MaintenancePlan', 200, 'write', 'При изменении mode/interval_unit/interval_count обязателен next_due_on. Существующий срок сохраняет снимок старого правила.'],
            'api.maintenance.plans.generate' => ['Сформировать ближайшую работу ТО', 'ExpectedVersion', 'NullableWorkResponse', 201, 'write', '200 с work=null, если срок не готов; 201 с работой при создании/получении подходящего срока. Массовый поток просрочек не создаётся.'],
            'api.maintenance.occurrences.decide' => ['Повторить/пропустить срок', 'OccurrenceDecision', 'NullableWorkResponse', 200, 'write', 'ВАЖНО: expected_version родительского ПЛАНА. retry после закрытия/отмены попыток; skip требует причину. Для completion пропуск не выдумывает новую дату выполнения.'],
            'api.inventory.index' => ['Инвентаризационные кампании', null, 'CampaignPage', 200, 'read', '50 кампаний на странице.'],
            'api.inventory.store' => ['Создать кампанию', 'CampaignCreate', 'InventoryCampaign', 201, 'write', 'Область задаётся UUID помещений/предметов и числовыми ID категорий. Снимок формируется при start.'],
            'api.inventory.show' => ['Кампания и результаты сверки', null, 'CampaignDetail', 200, 'read', 'До закрытия отчёт вычисляется; после закрытия возвращается замороженный payload.'],
            'api.inventory.start' => ['Начать инвентаризацию', 'ExpectedVersion', 'InventoryCampaign', 200, 'write', 'draft→active. Снимок состава/версий/помещений фиксируется; пересекающиеся активные кампании блокируются.'],
            'api.inventory.observations.store' => ['Зафиксировать наблюдение', 'ObservationCreate', 'InventoryObservation', 201, 'write', 'Только active. code — точный human code, не QR URL/UUID. client_key повторяем для повторной отправки одного наблюдения; иное содержимое с тем же ключом — 409. Наблюдение не перемещает имущество.'],
            'api.inventory.reconcile' => ['Остановить обход, начать сверку', 'ExpectedVersion', 'InventoryCampaign', 200, 'write', 'active→reconciling. Приём новых наблюдений прекращается.'],
            'api.inventory.resolutions.store' => ['Принять решение по расхождению', 'ResolutionCreate', 'InventoryResolution', 201, 'write', 'Только reconciling. asset_expected_version обязателен с asset_uuid; follow_up требует ответственного. move требует свежее observation_uuid этого предмета и отсутствия физических связей; ignore_unknown только для неизвестной метки.'],
            'api.inventory.close' => ['Закрыть кампанию', 'ExpectedVersion', 'InventoryReport', 200, 'write', 'Только reconciling. Все расхождения/extra/unknown должны иметь действующее решение. Отчёт замораживается, резервирования снимаются.'],
            'api.inventory.cancel' => ['Отменить кампанию', 'CampaignCancel', 'InventoryCampaign', 200, 'write', 'Только незавершённая кампания, обязательна причина. История не удаляется.'],
            'api.inventory.report' => ['Замороженный отчёт', null, 'InventoryReportPayload', 200, 'read', 'Только closed; до закрытия — 409.'],
            'api.labels.index' => ['Профили печати и выборка', null, 'LabelsIndexResponse', 200, 'read', 'До 500 объектов; truncated показывает усечение выборки.'],
            'api.labels.store' => ['Сохранить профиль печати', 'LabelProfileCommand', 'LabelProfileResponse', 201, 'write', 'Все размеры в мм с точностью 0.01; каждая сторона листа 50–420 мм; не более 500 ячеек, сетка с offsets должна помещаться. Для изменения укажите profile_id и expected_version.'],
            'api.labels.preview' => ['Рассчитать раскладку этикеток', 'LabelBatch', 'LabelPreviewResponse', 200, 'read', 'POST без изменения данных и без Idempotency-Key. target_type asset/location различает имущество и кабинеты. start_cell от 1, заполнение по строкам; до 500 меток и 50 листов.'],
            'api.labels.pdf' => ['Сформировать PDF наклеек', 'LabelBatch', 'PDFDocument', 200, 'read', 'POST чтения без Idempotency-Key. Локальная генерация QR/PDF. Печатать 100% / Actual size, отключив Fit to page.'],
            'api.labels.test' => ['Контрольный лист печати', 'LabelGeometryOverride', 'PDFDocument', 200, 'read', 'POST чтения без Idempotency-Key. Контрольный отрезок 100 мм; параметры принтера сервис автоматически не измеряет.'],
        ];
    }

    private static function schemas(): array
    {
        $s = [];
        $version = ['expected_version' => self::integer(1, description: 'Версия изменяемого агрегата из последнего ответа; устаревшая версия — 409.')];
        $date = self::date();
        $nullableDate = self::date(true);
        $text = self::string(10000, true);
        $condition = self::enum(['working', 'limited', 'faulty', 'unknown']);
        $status = self::enum(['stock', 'in_use', 'repair', 'written_off']);
        $policy = self::enum(['unspecified', 'none', 'reactive_only', 'scheduled']);
        $priority = self::enum(['low', 'normal', 'high', 'critical']);
        $kind = self::enum(['inspection', 'preventive', 'diagnostics', 'repair', 'upgrade', 'external']);
        $subject = ['asset_uuid' => self::uuid(true), 'software_installation_id' => self::id(true)];
        $subjectRule = ['oneOf' => [self::subjectBranch('asset_uuid', 'software_installation_id'), self::subjectBranch('software_installation_id', 'asset_uuid')]];

        $s['ExpectedVersion'] = self::object($version, ['expected_version']);
        $asset = ['name' => self::string(255), 'category_id' => self::id(), 'location_id' => self::id(true), 'responsible_name' => self::string(255, true), 'inventory_no' => self::string(255, true, 'Не уникальный бухгалтерский номер; не идентификатор QR.'), 'serial_no' => self::string(255, true), 'manufacturer' => self::string(255, true), 'model' => self::string(255, true), 'status' => self::nullable($status), 'condition' => $condition, 'maintenance_policy' => $policy, 'private_notes' => $text, 'purchased_on' => $nullableDate, 'warranty_until' => $nullableDate];
        $s['AssetCreate'] = self::object($asset, ['name', 'category_id', 'condition', 'maintenance_policy']);
        $assetUpdate = $asset;
        unset($assetUpdate['location_id'], $assetUpdate['status']);
        $s['AssetUpdate'] = self::object($assetUpdate + $version, ['name', 'category_id', 'condition', 'maintenance_policy', 'expected_version']) + ['not' => ['anyOf' => [['required' => ['location_id']], ['required' => ['status']]]]];
        $s['AssetMove'] = self::object($version + ['location_id' => self::id(true), 'status' => self::nullable($status), 'reason' => self::string(2000), 'include_children' => ['type' => ['boolean', 'null'], 'default' => false], 'expected_contents' => self::nullable(['type' => 'object', 'propertyNames' => ['type' => 'string', 'format' => 'uuid'], 'additionalProperties' => self::integer(1), 'description' => 'Карта всех физических потомков UUID→version: {"8917eafd-9f49-43e8-8c36-73421836c676":3}. При наличии содержимого должна точно совпадать с актуальным составом.'])], ['expected_version', 'location_id', 'reason']);
        $s['ComponentCreate'] = self::object($version + ['component_type' => self::string(100), 'description' => self::string(500, true), 'quantity' => self::integer(1, 1000), 'serial_no' => self::string(255, true), 'inventory_no' => self::string(255, true), 'child_asset_id' => self::id(true), 'expected_child_version' => self::nullable(self::integer(1))], ['expected_version', 'component_type', 'quantity']) + ['anyOf' => [['required' => ['child_asset_id'], 'properties' => ['child_asset_id' => self::id()]], ['required' => ['description'], 'properties' => ['description' => self::string(500)]]], 'allOf' => [['if' => ['required' => ['child_asset_id'], 'properties' => ['child_asset_id' => self::id()]], 'then' => ['required' => ['expected_child_version'], 'properties' => ['expected_child_version' => self::integer(1), 'quantity' => ['const' => 1]]]]]];
        $s['RackPlacement'] = self::object($version + ['rack_id' => self::id(), 'expected_rack_version' => self::integer(1), 'position' => self::string(100, true)], ['expected_version', 'rack_id', 'expected_rack_version']);
        $building = ['name' => self::string(255), 'address' => self::string(255, true), 'notes' => self::string(5000, true)];
        $s['BuildingCreate'] = self::object($building, ['name']);
        $s['BuildingUpdate'] = self::object($building + $version, ['name', 'expected_version']);
        $location = ['name' => self::string(255), 'building_id' => self::id(true), 'notes' => self::string(5000, true), 'is_public' => ['type' => 'boolean', 'default' => true]];
        $s['LocationCreate'] = self::object($location, ['name']);
        $s['LocationUpdate'] = self::object($location + $version, ['name', 'is_public', 'expected_version']);
        $s['KitCreate'] = self::object(['name' => self::string(255), 'description' => self::string(5000, true), 'asset_ids' => self::nullable(self::array(self::id(), 100, unique: true))], ['name']);
        $s['KitMemberCommand'] = self::object($version + ['asset_id' => self::id(), 'remove' => ['type' => ['boolean', 'null'], 'default' => false]], ['expected_version', 'asset_id']);

        $software = ['name' => self::string(255), 'vendor' => self::string(255, true), 'kind' => self::enum(['third_party', 'in_house', 'firmware']), 'owner_id' => self::id(true), 'repository_url' => ['type' => ['string', 'null'], 'format' => 'uri', 'maxLength' => 2048, 'pattern' => '^https?://', 'description' => 'Без пароля/токена в URL. Ссылка не скачивается и не исполняется.'], 'description' => $text];
        $s['SoftwareCreate'] = self::object($software, ['name', 'kind']);
        $s['SoftwareUpdate'] = self::object($software + $version, ['name', 'kind', 'expected_version']);
        $s['ReleaseCreate'] = self::object($version + ['version_name' => self::string(120), 'released_on' => $nullableDate, 'supported_until' => $nullableDate, 'notes' => $text], ['expected_version', 'version_name']);
        $s['InstallationCommand'] = self::object($version + ['software_release_id' => self::id(true), 'asset_id' => self::id(true), 'environment' => self::string(160, true), 'service_name' => self::string(255, true), 'installed_on' => $nullableDate, 'verified_now' => ['type' => 'boolean'], 'notes' => $text], ['expected_version']) + ['anyOf' => [['required' => ['asset_id'], 'properties' => ['asset_id' => self::id()]], ['required' => ['environment'], 'properties' => ['environment' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160, 'pattern' => '\\S']]]]];
        $license = ['name' => self::string(255, true), 'external_reference' => self::string(255, true), 'metric' => self::enum(['device', 'user', 'instance', 'unknown']) + ['default' => 'device'], 'capacity' => self::nullable(self::integer(0, 1000000000)), 'expires_on' => $nullableDate, 'notes' => $text];
        $secret = self::string(16384) + ['writeOnly' => true, 'description' => 'Секретный код/ключ. Хранится только зашифрованным, не возвращается в обычных ответах. Не журналировать.'];
        $s['LicenseCreate'] = self::object(['software_product_id' => self::id(), 'license_key' => self::nullable($secret) + ['writeOnly' => true], 'asset_id' => self::id(true)] + $license, ['software_product_id']);
        $s['LicenseUpdate'] = self::object($version + $license, ['expected_version']);
        $s['LicenseKeyCommand'] = self::object($version + ['license_key' => $secret], ['expected_version', 'license_key']);
        $s['LicenseAssign'] = self::object($version + ['asset_id' => self::id()], ['expected_version', 'asset_id']);

        $s['ChecklistStep'] = self::object(['key' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,39}$'], 'label' => self::string(200), 'type' => self::enum(['check', 'inspection', 'number', 'text']), 'required' => ['type' => 'boolean']], ['key', 'label', 'type', 'required'], false);
        $checklist = ['checklist_schema' => self::nullable(self::array(self::ref('ChecklistStep'), 100))];
        $work = $subject + ['title' => self::string(200), 'kind' => $kind, 'priority' => $priority, 'description' => $text, 'due_on' => $nullableDate, 'assignee_id' => self::id(true), 'requires_other_acceptor' => ['type' => 'boolean']] + $checklist;
        $s['WorkCreate'] = self::object($work, ['title', 'kind', 'priority']) + $subjectRule;
        $s['Material'] = self::object(['name' => self::string(200), 'quantity' => ['type' => 'number', 'exclusiveMinimum' => 0, 'maximum' => 1000000], 'unit_cost' => self::nullable(['type' => 'number', 'minimum' => 0, 'maximum' => 999999999999])], ['name', 'quantity']);
        $s['ChecklistAnswer'] = self::object(['value' => ['type' => ['boolean', 'number', 'string', 'null'], 'description' => 'Зависит от шага: check — bool/0/1; inspection — pass|flag|fail|na; number — конечное число; text — до 10000 символов. Обязательный check должен быть true/1.'], 'note' => self::string(10000, true, 'Обязательно непустое для inspection flag/fail/na.')]);
        $completion = ['result' => self::string(20000), 'completed_at' => ['type' => 'string', 'description' => 'Фактическая дата/время, не в будущем. Предпочтительно RFC3339 с offset. Без offset используется Asia/Sakhalin.', 'examples' => ['2026-10-15T12:00:00+11:00']], 'reported_condition' => self::nullable($condition), 'remaining_restrictions' => $text, 'checklist_answers' => self::nullable(['type' => 'object', 'additionalProperties' => self::ref('ChecklistAnswer')]), 'labour_minutes' => self::nullable(self::integer(0, 1000000)), 'service_cost' => self::nullable(['type' => 'number', 'minimum' => 0, 'maximum' => 999999999999]), 'materials' => self::nullable(self::array(self::ref('Material'), 100))];
        $s['WorkComplete'] = self::object($version + $completion, ['expected_version', 'result', 'completed_at']);
        $s['CompletedWorkCreate'] = self::object($work + $completion, ['title', 'kind', 'priority', 'result', 'completed_at']) + $subjectRule;
        $s['WorkUpdate'] = self::object($version + ['assignee_id' => self::id(true), 'due_on' => $nullableDate, 'priority' => $priority, 'reason' => self::string(10000)], ['expected_version', 'priority', 'reason']);
        $s['WorkTransition'] = self::object($version + ['action' => self::enum(['start', 'hold', 'resume', 'cancel', 'reject_result']), 'reason' => $text], ['expected_version', 'action']) + ['allOf' => [['if' => ['properties' => ['action' => ['enum' => ['hold', 'cancel', 'reject_result']]]], 'then' => ['required' => ['reason'], 'properties' => ['reason' => self::string(10000)]]]]];
        $s['WorkAccept'] = self::object($version + ['note' => self::string(10000), 'counts_for_plan' => ['type' => 'boolean']], ['expected_version', 'note', 'counts_for_plan']);
        $s['WorkCorrection'] = self::object($version + ['reason' => self::string(10000), 'result' => self::string(20000)], ['expected_version', 'reason', 'result']);
        $s['MaintenanceRequestCreate'] = self::object($subject + ['title' => self::string(200), 'description' => self::string(10000), 'priority' => $priority], ['title', 'description', 'priority']) + $subjectRule;
        $s['RequestTriage'] = self::object($version + ['decision' => self::enum(['accepted', 'rejected', 'needs_info', 'merged']), 'reason' => self::string(10000), 'work_order_uuid' => self::uuid(true)], ['expected_version', 'decision', 'reason']) + ['allOf' => [['if' => ['properties' => ['decision' => ['const' => 'merged']]], 'then' => ['required' => ['work_order_uuid'], 'properties' => ['work_order_uuid' => self::uuid()]]]]];
        $schedule = ['name' => self::string(200), 'mode' => self::enum(['calendar', 'completion']), 'interval_unit' => self::enum(['day', 'week', 'month', 'year']), 'interval_count' => self::integer(1, 120), 'lead_days' => self::integer(0, 365), 'assignee_id' => self::id(true), 'requires_other_acceptor' => ['type' => 'boolean']] + $checklist;
        $s['PlanCreate'] = self::object($subject + $schedule + ['first_due_on' => $nullableDate], ['name', 'mode', 'interval_unit', 'interval_count', 'lead_days']) + $subjectRule;
        $s['PlanUpdate'] = self::object($version + $schedule + ['status' => self::enum(['active', 'paused']), 'next_due_on' => $nullableDate, 'reason' => self::string(10000)], ['expected_version', 'status', 'reason']);
        $s['OccurrenceDecision'] = self::object(['expected_version' => self::integer(1, description: 'Версия РОДИТЕЛЬСКОГО ПЛАНА, не occurrence и не work.'), 'action' => self::enum(['retry', 'skip']), 'reason' => self::string(10000)], ['expected_version', 'action', 'reason']);

        $s['CampaignCreate'] = self::object(['name' => self::string(200), 'location_uuids' => self::nullable(self::array(self::uuid(), 500)), 'category_ids' => self::nullable(self::array(self::id(), 500)), 'asset_uuids' => self::nullable(self::array(self::uuid(), 5000))], ['name']);
        $s['CampaignCancel'] = self::object($version + ['reason' => self::string(10000)], ['expected_version', 'reason']);
        $s['ObservationCreate'] = self::object($version + ['code' => self::string(500, false, 'Точный код предмета; неизвестные коды записываются как неизвестные наблюдения.'), 'location_uuid' => self::uuid(), 'client_key' => self::string(80, false, 'Стабильный идентификатор одного наблюдения; повторяется при повторной отправке.'), 'method' => self::enum(['qr', 'manual']), 'condition' => self::nullable($condition), 'note' => $text], ['expected_version', 'code', 'location_uuid', 'client_key', 'method']);
        $s['ResolutionCreate'] = self::object($version + ['asset_uuid' => self::uuid(true), 'observation_uuid' => self::uuid(true), 'asset_expected_version' => self::nullable(self::integer(1)), 'decision' => self::enum(['confirmed', 'move', 'not_found', 'follow_up', 'ignore_unknown']), 'reason' => self::string(10000), 'follow_up_assignee_id' => self::id(true)], ['expected_version', 'decision', 'reason']) + ['allOf' => [['if' => ['required' => ['asset_uuid'], 'properties' => ['asset_uuid' => self::uuid()]], 'then' => ['required' => ['asset_expected_version'], 'properties' => ['asset_expected_version' => self::integer(1)]]], ['if' => ['properties' => ['decision' => ['const' => 'follow_up']]], 'then' => ['required' => ['follow_up_assignee_id'], 'properties' => ['follow_up_assignee_id' => self::id()]]], ['if' => ['properties' => ['decision' => ['const' => 'move']]], 'then' => ['required' => ['asset_uuid', 'observation_uuid']]]], 'anyOf' => [['required' => ['asset_uuid'], 'properties' => ['asset_uuid' => self::uuid()]], ['required' => ['observation_uuid'], 'properties' => ['observation_uuid' => self::uuid()]]]];

        $geometry = [];
        foreach (['paper_width_mm', 'paper_height_mm', 'label_width_mm', 'label_height_mm', 'gap_x_mm', 'gap_y_mm', 'margin_left_mm', 'margin_top_mm', 'offset_x_mm', 'offset_y_mm'] as $field) {
            $geometry[$field] = ['type' => 'number', 'minimum' => str_starts_with($field, 'offset_') ? -1000 : 0, 'maximum' => 1000, 'multipleOf' => 0.01, 'description' => 'Миллиметры. Геометрия проверяется целиком; размеры >0, сетка в пределах листа.'];
        }
        $geometry['paper_width_mm']['minimum'] = 50;
        $geometry['paper_width_mm']['maximum'] = 420;
        $geometry['paper_height_mm']['minimum'] = 50;
        $geometry['paper_height_mm']['maximum'] = 420;
        $geometry['label_width_mm']['exclusiveMinimum'] = 0;
        $geometry['label_height_mm']['exclusiveMinimum'] = 0;
        $geometry += ['columns' => self::integer(1, 500), 'rows' => self::integer(1, 500)];
        $s['LabelGeometry'] = self::object($geometry, array_keys($geometry));
        $s['LabelGeometryOverride'] = self::object(['profile_id' => self::id(true)] + $geometry);
        $s['LabelProfileCommand'] = self::object(['name' => self::string(100), 'profile_id' => self::id(true)] + $version + $geometry, array_merge(['name'], array_keys($geometry))) + ['allOf' => [['if' => ['required' => ['profile_id'], 'properties' => ['profile_id' => self::id()]], 'then' => ['required' => ['expected_version']]]]];
        $s['LabelBatch'] = self::object(['profile_id' => self::id(true)] + $geometry + ['target_type' => self::enum(['asset', 'location']), 'target_ids' => self::array(self::uuid(), 500, 1, true), 'start_cell' => self::integer(1, 500, 'Номер с 1, не выше rows×columns; следующие листы начинаются с 1.')], ['target_type', 'target_ids', 'start_cell']);

        return $s + self::responseSchemas($condition, $status, $policy, $priority, $kind);
    }

    private static function responseSchemas(array $condition, array $status, array $policy, array $priority, array $kind): array
    {
        $s = [];
        $record = ['id' => self::id(), 'uuid' => self::uuid(), 'version' => self::integer(1)];
        $timestamps = ['created_at' => self::datetime(true), 'updated_at' => self::datetime(true)];
        $calendar = ['anyOf' => [self::date(), self::datetime(), ['type' => 'null']], 'description' => 'Календарная дата. Eloquent date casts могут сериализовать её как ISO8601 с временем 00:00:00Z.'];
        $nullableText = self::string(10000, true);
        $money = ['type' => ['string', 'number', 'null'], 'description' => 'Точная десятичная стоимость; Eloquent decimal сериализуется строкой.'];
        $s['Error'] = self::object(['message' => ['type' => 'string']], ['message']);
        $s['ValidationError'] = self::object(['message' => ['type' => 'string'], 'errors' => ['type' => 'object', 'additionalProperties' => self::array(['type' => 'string'])]], ['message', 'errors']);
        $s['CommandError'] = ['anyOf' => [self::ref('ValidationError'), self::ref('Error')], 'description' => '422 полевой валидации содержит errors; доменное abort(422) может содержать только message.'];
        $s['Category'] = self::object(['id' => self::id(), 'name' => self::string(255), 'slug' => self::string(255), 'domain' => self::enum(['it', 'audio', 'stage', 'unknown']), 'kind' => self::enum(['standard', 'computer', 'rack']), 'maintenance_policy' => $policy], ['id', 'name', 'slug', 'domain', 'kind', 'maintenance_policy']);
        $s['CategoryList'] = self::object(['data' => self::array(self::ref('Category'))], ['data']);
        $s['OkResponse'] = self::object(['ok' => ['type' => 'boolean', 'const' => true]], ['ok']);
        $s['UserSummary'] = self::object(['id' => self::id(), 'name' => self::string(255)], ['id', 'name']);
        $s['BuildingSummary'] = self::object(['id' => self::id(), 'uuid' => self::uuid(), 'name' => self::string(255)], ['id', 'uuid', 'name'], false);
        $s['Building'] = self::object($record + ['name' => self::string(255), 'address' => self::string(255, true), 'archived_at' => self::datetime(true), 'locations_count' => self::integer(0)] + $timestamps, ['id', 'uuid', 'version', 'name']);
        $s['BuildingResponse'] = self::object(['data' => self::ref('Building')], ['data']);
        $s['BuildingPage'] = self::object(['data' => self::array(self::ref('Building')), 'meta' => self::object(['total' => self::integer(0)], ['total'])], ['data', 'meta']);
        $s['BuildingDetail'] = self::object(['data' => self::ref('Building'), 'locations' => self::array(self::ref('Location')), 'meta' => self::object(['total' => self::integer(0)], ['total'])], ['data', 'locations', 'meta']);
        $s['Asset'] = self::object($record + ['code' => self::string(64), 'name' => self::string(255), 'category_id' => self::id(), 'location_id' => self::id(true), 'location_uuid' => self::uuid(true), 'building' => self::nullable(self::ref('BuildingSummary')), 'inventory_no' => self::string(255, true), 'serial_no' => self::string(255, true), 'manufacturer' => self::string(255, true), 'model' => self::string(255, true), 'status' => $status, 'condition' => $condition, 'maintenance_policy' => $policy], ['id', 'uuid', 'version', 'code', 'name', 'category_id', 'location_id', 'status', 'condition']);
        $s['AssetResponse'] = self::object(['data' => self::ref('Asset')], ['data']);
        $s['AssetDetail'] = ['allOf' => [self::ref('Asset'), self::object(['responsible_name' => self::string(255, true), 'private_notes' => $nullableText, 'specifications' => self::nullable(['type' => ['object', 'array'], 'description' => 'Внутренние характеристики, не гостевой whitelist.']), 'purchased_on' => self::date(true), 'warranty_until' => self::date(true), 'components' => self::array(self::object(['id' => self::id(), 'component_type' => self::string(100), 'description' => self::string(500), 'quantity' => self::integer(1), 'serial_no' => self::string(255, true), 'inventory_no' => self::string(255, true), 'child_asset' => self::nullable(self::ref('Asset'))], ['id', 'component_type', 'description', 'quantity', 'child_asset'])), 'placement' => self::nullable(self::object(['kind' => self::enum(['component', 'rack']), 'position' => self::string(100, true), 'parent_asset' => self::ref('Asset')], ['kind', 'parent_asset'])), 'contents' => self::array(self::ref('Asset')), 'licenses' => self::array(self::object(['assignment_id' => self::id(), 'uuid' => self::uuid(), 'code' => self::string(64), 'product_name' => self::string(255, true)], ['assignment_id', 'uuid', 'code'])), 'software' => self::array(self::object(['id' => self::id(), 'product_uuid' => self::uuid(), 'product_name' => self::string(255), 'version' => self::integer(1, description: 'Версия записи установки для optimistic locking, не номер выпуска ПО.')], ['id', 'product_uuid', 'product_name', 'version']))], ['components', 'placement', 'contents', 'licenses', 'software'])]];
        $s['AssetDetailResponse'] = self::object(['data' => self::ref('AssetDetail')], ['data']);
        $s['AssetPage'] = self::object(['data' => self::array(self::ref('Asset')), 'meta' => self::object(['page' => self::integer(1), 'last_page' => self::integer(1), 'total' => self::integer(0)], ['page', 'last_page', 'total'])], ['data', 'meta']);
        $s['AssetComponent'] = self::object(['id' => self::id(), 'parent_asset_id' => self::id(), 'child_asset_id' => self::id(true), 'component_type' => self::string(100), 'description' => self::string(500), 'quantity' => self::integer(1), 'serial_no' => self::string(255, true), 'inventory_no' => self::string(255, true), 'installed_at' => self::datetime(), 'removed_at' => self::datetime(true)] + $timestamps, ['id', 'parent_asset_id', 'component_type', 'description', 'quantity']);
        $s['ComponentResponse'] = self::object(['data' => self::ref('AssetComponent')], ['data']);
        $s['Location'] = self::object($record + ['code' => self::string(64), 'name' => self::string(255), 'parent_id' => self::id(true), 'building_id' => self::id(true), 'building' => self::nullable(self::ref('BuildingSummary')), 'is_public' => ['type' => 'boolean'], 'archived_at' => self::datetime(true), 'assets_count' => self::integer(0)] + $timestamps, ['id', 'uuid', 'version', 'code', 'name', 'is_public']);
        $s['LocationResponse'] = self::object(['data' => self::ref('Location')], ['data']);
        $s['LocationDetail'] = self::object(['data' => self::ref('Location'), 'assets' => self::array(self::ref('Asset'))], ['data', 'assets']);
        $s['LocationPage'] = self::object(['data' => self::array(self::ref('Location')), 'meta' => self::object(['total' => self::integer(0)], ['total'])], ['data', 'meta']);
        $s['Kit'] = self::object($record + ['code' => self::string(64), 'name' => self::string(255), 'description' => self::string(5000, true), 'archived_at' => self::datetime(true), 'assets' => self::array(self::ref('Asset'))] + $timestamps, ['id', 'uuid', 'version', 'code', 'name']);
        $s['KitList'] = self::object(['data' => self::array(self::ref('Kit'))], ['data']);
        $s['KitResponse'] = self::object(['data' => self::ref('Kit')], ['data']);

        $s['SoftwareProduct'] = self::object($record + ['name' => self::string(255), 'vendor' => self::string(255, true), 'kind' => self::enum(['third_party', 'in_house', 'firmware']), 'owner_id' => self::id(true), 'description' => $nullableText, 'archived_at' => self::datetime(true), 'owner' => self::nullable(self::ref('UserSummary')), 'installations_count' => self::integer(0), 'licenses_count' => self::integer(0)] + $timestamps, ['id', 'uuid', 'version', 'name', 'kind']);
        $s['SoftwareRelease'] = self::object(['id' => self::id(), 'software_product_id' => self::id(), 'version_name' => self::string(120), 'released_on' => $calendar, 'supported_until' => $calendar, 'notes' => $nullableText] + $timestamps, ['id', 'software_product_id', 'version_name']);
        $s['SoftwareInstallation'] = self::object(['id' => self::id(), 'software_product_id' => self::id(), 'software_release_id' => self::id(true), 'asset_id' => self::id(true), 'environment' => self::string(160, true), 'service_name' => self::string(255, true), 'installed_on' => $calendar, 'last_verified_at' => self::datetime(true), 'notes' => $nullableText, 'version' => self::integer(1), 'archived_at' => self::datetime(true), 'asset' => self::nullable(self::ref('Asset')), 'release' => self::nullable(self::ref('SoftwareRelease'))] + $timestamps, ['id', 'software_product_id', 'version']);
        $s['SoftwareDetail'] = ['allOf' => [self::ref('SoftwareProduct'), self::object(['repository_url' => self::string(2048, true), 'releases' => self::array(self::ref('SoftwareRelease')), 'installations' => self::array(self::ref('SoftwareInstallation')), 'licenses' => self::array(self::ref('LicenseInstance'))], ['releases', 'installations', 'licenses'])]];
        $s['SoftwarePage'] = self::paginator('SoftwareProduct');
        $s['LicenseUsage'] = self::object(['equipment_links' => self::integer(0), 'remaining' => self::nullable(self::integer(0)), 'needs_review' => ['type' => 'boolean']], ['equipment_links', 'remaining', 'needs_review']) + ['description' => 'remaining вычисляется только для device с известной capacity. Иначе null и needs_review=true; это не автоматическое подтверждение лицензионной чистоты.'];
        $s['LicenseInstance'] = self::object($record + ['code' => self::string(64), 'software_product_id' => self::id(), 'name' => self::string(255, true), 'external_reference' => self::string(255, true), 'metric' => self::enum(['device', 'user', 'instance', 'unknown']), 'capacity' => self::nullable(self::integer(0)), 'expires_on' => $calendar, 'notes' => $nullableText, 'archived_at' => self::datetime(true), 'product' => self::ref('SoftwareProduct'), 'active_assignments_count' => self::integer(0), 'secret_exists' => ['type' => 'boolean'], 'usage' => self::ref('LicenseUsage')] + $timestamps, ['id', 'uuid', 'code', 'software_product_id', 'metric', 'version']);
        $s['LicenseAssignment'] = self::object(['id' => self::id(), 'license_instance_id' => self::id(), 'asset_id' => self::id(), 'assigned_at' => self::datetime(), 'released_at' => self::datetime(true), 'actor_id' => self::id(), 'asset' => self::ref('Asset'), 'actor' => self::ref('UserSummary')] + $timestamps, ['id', 'license_instance_id', 'asset_id', 'assigned_at']);
        $s['LicenseResponse'] = self::object(['license' => self::ref('LicenseInstance'), 'warnings' => self::array(['type' => 'string']), 'usage' => self::ref('LicenseUsage')], ['license', 'warnings', 'usage']);
        $s['LicenseDetailResponse'] = self::object(['license' => ['allOf' => [self::ref('LicenseInstance'), self::object(['assignments' => self::array(self::ref('LicenseAssignment'))], ['assignments'])]], 'warnings' => self::array(['type' => 'string']), 'usage' => self::ref('LicenseUsage')], ['license', 'warnings', 'usage']);
        $s['LicensePage'] = self::paginator('LicenseInstance');
        $s['LicenseRevealResponse'] = self::object(['license_uuid' => self::uuid(), 'license_key' => self::string(16384)], ['license_uuid', 'license_key']) + ['description' => 'Содержит секрет! Только выделенный no-store endpoint администратора; не кешировать и не логировать.'];

        $s['WorkOrder'] = self::object($record + ['asset_id' => self::id(true), 'software_installation_id' => self::id(true), 'maintenance_occurrence_id' => self::id(true), 'follow_up_for_id' => self::id(true), 'attempt' => self::integer(1), 'title' => self::string(200), 'kind' => $kind, 'priority' => $priority, 'status' => self::enum(['open', 'in_progress', 'on_hold', 'completed', 'closed', 'cancelled']), 'resume_status' => self::string(20, true), 'description' => $nullableText, 'due_on' => $calendar, 'assignee_id' => self::id(true), 'created_by' => self::id(), 'checklist_schema' => self::nullable(self::array(self::ref('ChecklistStep'))), 'checklist_answers' => self::nullable(['type' => 'object', 'additionalProperties' => self::ref('ChecklistAnswer')]), 'result' => self::string(20000, true), 'reported_condition' => self::nullable($condition), 'remaining_restrictions' => $nullableText, 'materials' => self::nullable(self::array(self::ref('Material'))), 'labour_minutes' => self::nullable(self::integer(0)), 'service_cost' => $money, 'completed_at' => self::datetime(true), 'result_recorded_at' => self::datetime(true), 'completed_by' => self::id(true), 'accepted_at' => self::datetime(true), 'accepted_by' => self::id(true), 'acceptance_note' => $nullableText, 'counts_for_plan' => ['type' => 'boolean'], 'requires_other_acceptor' => ['type' => 'boolean']] + $timestamps, ['id', 'uuid', 'version', 'title', 'kind', 'priority', 'status']);
        $s['WorkEvent'] = self::object(['id' => self::id(), 'work_order_id' => self::id(), 'actor_id' => self::id(true), 'event' => self::string(50), 'from_status' => self::string(20, true), 'to_status' => self::string(20), 'reason' => $nullableText, 'data' => self::nullable(['type' => 'object', 'description' => 'Данные события зависят от event: предыдущий результат, ответы, причина принятия.']), 'created_at' => self::datetime()], ['id', 'event', 'to_status', 'created_at']);
        $s['WorkDetail'] = ['allOf' => [self::ref('WorkOrder'), self::object(['events' => self::array(self::ref('WorkEvent'))], ['events'])]];
        $s['WorkPage'] = self::paginator('WorkOrder');
        $s['NullableWorkResponse'] = self::object(['work' => self::nullable(self::ref('WorkOrder'))], ['work']);
        $s['MaintenanceRequest'] = self::object($record + ['asset_id' => self::id(true), 'software_installation_id' => self::id(true), 'title' => self::string(200), 'description' => self::string(10000), 'priority' => $priority, 'status' => self::enum(['new', 'needs_info', 'accepted', 'rejected', 'merged']), 'decision_note' => $nullableText, 'work_order_id' => self::id(true), 'created_by' => self::id()] + $timestamps, ['id', 'uuid', 'version', 'title', 'status']);
        $s['MaintenanceRequestPage'] = self::paginator('MaintenanceRequest');
        $s['MaintenancePlan'] = self::object($record + ['asset_id' => self::id(true), 'software_installation_id' => self::id(true), 'name' => self::string(200), 'mode' => self::enum(['calendar', 'completion']), 'interval_unit' => self::enum(['day', 'week', 'month', 'year']), 'interval_count' => self::integer(1, 120), 'lead_days' => self::integer(0, 365), 'anchor_on' => $calendar, 'next_due_on' => $calendar, 'last_completed_on' => $calendar, 'status' => self::enum(['active', 'paused']), 'checklist_schema' => self::nullable(self::array(self::ref('ChecklistStep'))), 'assignee_id' => self::id(true), 'created_by' => self::id(), 'requires_other_acceptor' => ['type' => 'boolean']] + $timestamps, ['id', 'uuid', 'version', 'name', 'mode', 'interval_unit', 'interval_count', 'status']);
        $s['MaintenanceOccurrence'] = self::object(['id' => self::id(), 'uuid' => self::uuid(), 'maintenance_plan_id' => self::id(), 'sequence' => self::integer(1), 'due_on' => $calendar, 'status' => self::enum(['pending', 'completed', 'skipped']), 'schedule_snapshot' => self::object(['mode' => self::enum(['calendar', 'completion']), 'anchor_on' => $calendar, 'interval_unit' => self::enum(['day', 'week', 'month', 'year']), 'interval_count' => self::integer(1), 'lead_days' => self::integer(0)]), 'missed_periods' => self::integer(0), 'skip_reason' => $nullableText, 'decided_by' => self::id(true), 'decided_at' => self::datetime(true), 'work_orders' => self::array(self::ref('WorkOrder'))] + $timestamps, ['id', 'uuid', 'maintenance_plan_id', 'due_on', 'status']);
        $s['PlanDetail'] = ['allOf' => [self::ref('MaintenancePlan'), self::object(['occurrences' => self::array(self::ref('MaintenanceOccurrence'))], ['occurrences'])]];
        $s['PlanPage'] = self::paginator('MaintenancePlan');

        $s['InventoryCampaign'] = self::object($record + ['name' => self::string(200), 'status' => self::enum(['draft', 'active', 'reconciling', 'closed', 'cancelled']), 'scope' => self::object(['location_uuids' => self::array(self::uuid()), 'category_ids' => self::array(self::id()), 'asset_uuids' => self::array(self::uuid())]), 'created_by' => self::id(), 'started_at' => self::datetime(true), 'reconciliation_at' => self::datetime(true), 'closed_at' => self::datetime(true), 'cancellation_reason' => $nullableText] + $timestamps, ['id', 'uuid', 'version', 'name', 'status']);
        $s['CampaignPage'] = self::paginator('InventoryCampaign');
        $s['InventoryObservation'] = self::object(['id' => self::id(), 'uuid' => self::uuid(), 'inventory_campaign_id' => self::id(), 'asset_id' => self::id(true), 'location_id' => self::id(), 'location_name' => self::string(255), 'asset_code' => self::string(64, true), 'asset_name' => self::string(255, true), 'raw_code' => self::string(500), 'asset_version' => self::nullable(self::integer(1)), 'method' => self::enum(['qr', 'manual']), 'condition' => self::nullable($condition), 'note' => $nullableText, 'actor_id' => self::id(), 'client_key' => self::string(80), 'observed_at' => self::datetime(), 'created_at' => self::datetime()], ['id', 'uuid', 'inventory_campaign_id', 'location_id', 'raw_code', 'method']);
        $decision = self::enum(['confirmed', 'move', 'not_found', 'follow_up', 'ignore_unknown']);
        $s['InventoryResolution'] = self::object(['id' => self::id(), 'uuid' => self::uuid(), 'inventory_campaign_id' => self::id(), 'asset_id' => self::id(true), 'inventory_observation_id' => self::id(true), 'decision' => $decision, 'reason' => self::string(10000), 'actor_id' => self::id(), 'follow_up_assignee_id' => self::id(true), 'asset_version' => self::nullable(self::integer(1)), 'created_at' => self::datetime()], ['id', 'uuid', 'inventory_campaign_id', 'decision', 'reason']);
        $s['ReportObservation'] = self::object(['uuid' => self::uuid(), 'asset_version' => self::nullable(self::integer(1)), 'location_id' => self::id(), 'location' => self::string(255), 'actor_id' => self::id(), 'at' => self::datetime(), 'method' => self::enum(['qr', 'manual']), 'condition' => self::nullable($condition), 'note' => $nullableText], ['uuid', 'location_id', 'location', 'actor_id', 'at', 'method']);
        $reportItem = ['asset_id' => self::id(), 'asset_uuid' => self::uuid(true), 'code' => self::string(64), 'name' => self::string(255), 'expected_location' => self::string(255, true), 'expected_location_id' => self::id(true), 'expected_responsible' => self::string(255, true), 'expected_status' => $status, 'expected_condition' => $condition, 'snapshot_version' => self::integer(1), 'current_version' => self::nullable(self::integer(1)), 'state' => self::enum(['unseen', 'conflict', 'stale', 'elsewhere', 'found', 'changed']), 'decision' => self::nullable($decision), 'reason' => $nullableText, 'decision_actor_id' => self::id(true), 'decision_at' => self::datetime(true), 'follow_up_assignee_id' => self::id(true), 'observations' => self::array(self::ref('ReportObservation'))];
        $s['ReportItem'] = self::object($reportItem, ['asset_id', 'code', 'name', 'state', 'observations']);
        $s['ReportExtra'] = self::object(array_intersect_key($reportItem, array_flip(['asset_id', 'asset_uuid', 'name', 'code', 'current_version', 'decision', 'reason', 'observations'])), ['asset_id', 'code', 'observations']);
        $s['ReportUnknown'] = ['allOf' => [self::ref('ReportObservation'), self::object(['raw_code' => self::string(500), 'decision' => self::nullable($decision), 'reason' => $nullableText], ['raw_code'])]];
        $s['InventoryReportPayload'] = self::object(['campaign' => self::object(['uuid' => self::uuid(), 'name' => self::string(200), 'started_at' => self::datetime(true), 'closed_at' => self::datetime(true)], ['uuid', 'name']), 'summary' => self::object(['expected' => self::integer(0), 'observed' => self::integer(0), 'extra' => self::integer(0), 'unknown' => self::integer(0)], ['expected', 'observed', 'extra', 'unknown']), 'items' => self::array(self::ref('ReportItem')), 'extra' => self::array(self::ref('ReportExtra')), 'unknown' => self::array(self::ref('ReportUnknown'))], ['campaign', 'summary', 'items', 'extra', 'unknown']);
        $s['InventoryReport'] = self::object(['id' => self::id(), 'uuid' => self::uuid(), 'inventory_campaign_id' => self::id(), 'revision' => self::integer(1), 'payload' => self::ref('InventoryReportPayload'), 'created_by' => self::id(), 'created_at' => self::datetime()], ['id', 'uuid', 'revision', 'payload']);
        $s['CampaignDetail'] = self::object(['campaign' => self::ref('InventoryCampaign'), 'report' => self::ref('InventoryReportPayload')], ['campaign', 'report']);

        $profile = ['id' => self::id(), 'name' => self::string(100), 'version' => self::integer(1), 'columns' => self::integer(1, 500), 'rows' => self::integer(1, 500)] + $timestamps;
        foreach (['paper_width_mm', 'paper_height_mm', 'label_width_mm', 'label_height_mm', 'gap_x_mm', 'gap_y_mm', 'margin_left_mm', 'margin_top_mm', 'offset_x_mm', 'offset_y_mm'] as $field) {
            $profile[$field] = ['type' => ['number', 'string'], 'description' => 'Точное число мм; в модели DECIMAL сериализуется строкой с двумя знаками.'];
        }
        $s['LabelProfile'] = self::object($profile, ['id', 'name', 'version', 'columns', 'rows']);
        $s['LabelProfileResponse'] = self::object(['data' => self::ref('LabelProfile')], ['data']);
        $s['LabelTarget'] = self::object(['uuid' => self::uuid(), 'code' => self::string(64), 'name' => self::string(255), 'type' => self::enum(['asset', 'location']), 'qr_url' => ['type' => 'string', 'format' => 'uri']], ['uuid', 'code', 'name']);
        $s['LabelCell'] = self::object(['index' => self::integer(0), 'cell' => self::integer(1), 'row' => self::integer(1), 'column' => self::integer(1), 'x_mm' => ['type' => 'number'], 'y_mm' => ['type' => 'number'], 'width_mm' => ['type' => 'number', 'exclusiveMinimum' => 0], 'height_mm' => ['type' => 'number', 'exclusiveMinimum' => 0]], ['index', 'cell', 'row', 'column', 'x_mm', 'y_mm', 'width_mm', 'height_mm']);
        $s['LabelLayout'] = self::object(['geometry' => self::ref('LabelGeometry'), 'capacity' => self::integer(1, 500), 'pages_count' => self::integer(1, 50), 'pages' => self::array(self::array(self::ref('LabelCell'), 500), 50)], ['geometry', 'capacity', 'pages_count', 'pages']);
        $s['LabelPreviewResponse'] = self::object(['data' => self::object(['layout' => self::ref('LabelLayout'), 'targets' => self::array(self::ref('LabelTarget'), 500), 'start_cell' => self::integer(1, 500)], ['layout', 'targets', 'start_cell'])], ['data']);
        $s['LabelsIndexResponse'] = self::object(['data' => self::object(['profiles' => self::array(self::ref('LabelProfile')), 'geometry' => self::ref('LabelGeometry'), 'targets' => self::array(self::ref('LabelTarget'), 500), 'target_type' => self::enum(['asset', 'location']), 'truncated' => ['type' => 'boolean'], 'limits' => self::object(['labels' => self::integer(1), 'pages' => self::integer(1)], ['labels', 'pages'])], ['profiles', 'geometry', 'targets', 'target_type', 'truncated', 'limits'])], ['data']);
        $s['PDFDocument'] = ['type' => 'string', 'format' => 'binary', 'description' => 'Готовый PDF выбранного размера, Content-Disposition: attachment; no-store.'];

        return $s;
    }

    private static function queryParameters(string $name): array
    {
        $result = [];
        if ((str_ends_with($name, '.index') && ! in_array($name, ['api.labels.index', 'api.categories.index'], true)) || in_array($name, ['api.locations.show', 'api.buildings.show'], true)) {
            $result[] = ['name' => 'page', 'in' => 'query', 'schema' => self::integer(1), 'description' => 'Номер страницы с 1; размер страницы фиксирован сервером.'];
        }
        if (in_array($name, ['api.assets.index', 'api.software.index', 'api.licenses.index', 'api.labels.index'], true)) {
            $result[] = ['name' => 'q', 'in' => 'query', 'schema' => self::string(in_array($name, ['api.assets.index', 'api.labels.index'], true) ? 150 : 200), 'description' => 'Поиск по названию/коду в соответствующем разделе.'];
        }
        if ($name === 'api.assets.index') {
            foreach (['category', 'location'] as $field) {
                $result[] = ['name' => $field, 'in' => 'query', 'schema' => self::id(), 'description' => 'Числовой ID справочника.'];
            }
        }
        if (in_array($name, ['api.assets.index', 'api.locations.index'], true)) {
            $result[] = ['name' => 'building_id', 'in' => 'query', 'schema' => ['anyOf' => [self::id(), ['type' => 'string', 'const' => 'unassigned']]], 'description' => 'Числовой ID здания или unassigned для кабинетов, у которых здание пока не указано. Имущество получает здание через свой кабинет.'];
        }
        if ($name === 'api.labels.index') {
            $result[] = ['name' => 'target_type', 'in' => 'query', 'schema' => self::enum(['asset', 'location']) + ['default' => 'asset']];
            $result[] = ['name' => 'profile_id', 'in' => 'query', 'schema' => self::id()];
        }

        return $result;
    }

    private static function errors(): array
    {
        $responses = [];
        foreach (['401' => 'Отсутствует/недействителен Bearer PAT.', '403' => 'Недостаточно прав роли или token abilities; недопустима самоприёмка.', '404' => 'Ресурс не найден или недоступен в указанном контексте.', '409' => 'Устаревшая expected_version, конфликт состояния, повтор ключа команды с другим запросом или конфликт сверки.', '422' => 'Ошибка полей или доменного правила.', '429' => 'Лимит API-запросов. Повторите с задержкой.', '500' => 'Ошибка сервера. Сохраните Idempotency-Key; перед повтором проверьте результат команды.'] as $code => $description) {
            $responses[$code] = ['description' => $description, 'content' => ['application/json' => ['schema' => self::ref($code === '422' ? 'CommandError' : 'Error')]]];
        }

        return $responses;
    }

    private static function paginator(string $item): array
    {
        return self::object(['current_page' => self::integer(1), 'data' => self::array(self::ref($item)), 'first_page_url' => self::string(2048, true), 'from' => self::nullable(self::integer(1)), 'last_page' => self::integer(1), 'last_page_url' => self::string(2048, true), 'links' => self::array(self::object(['url' => self::string(2048, true), 'label' => ['type' => 'string'], 'active' => ['type' => 'boolean']])), 'next_page_url' => self::string(2048, true), 'path' => self::string(2048), 'per_page' => self::integer(1), 'prev_page_url' => self::string(2048, true), 'to' => self::nullable(self::integer(1)), 'total' => self::integer(0)], ['current_page', 'data', 'last_page', 'per_page', 'total']);
    }

    private static function subjectBranch(string $selected, string $other): array
    {
        return ['required' => [$selected], 'properties' => [$selected => $selected === 'asset_uuid' ? self::uuid() : self::id(), $other => ['type' => 'null']]];
    }

    private static function object(array $properties, array $required = [], bool $additional = true): array
    {
        $schema = ['type' => 'object', 'properties' => $properties ?: (object) [], 'additionalProperties' => $additional];
        if ($required) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    private static function string(int $max, bool $nullable = false, string $description = ''): array
    {
        $schema = ['type' => $nullable ? ['string', 'null'] : 'string', 'maxLength' => $max];
        if (! $nullable) {
            $schema['minLength'] = 1;
        }
        if ($description !== '') {
            $schema['description'] = $description;
        }

        return $schema;
    }

    private static function integer(int $min, ?int $max = null, string $description = ''): array
    {
        $schema = ['type' => 'integer', 'minimum' => $min];
        if ($max !== null) {
            $schema['maximum'] = $max;
        }
        if ($description !== '') {
            $schema['description'] = $description;
        }

        return $schema;
    }

    private static function id(bool $nullable = false): array
    {
        $schema = self::integer(1) + ['format' => 'int64'];

        return $nullable ? self::nullable($schema) : $schema;
    }

    private static function uuid(bool $nullable = false): array
    {
        return ['type' => $nullable ? ['string', 'null'] : 'string', 'format' => 'uuid'];
    }

    private static function enum(array $values): array
    {
        return ['type' => 'string', 'enum' => $values];
    }

    private static function date(bool $nullable = false): array
    {
        return ['type' => $nullable ? ['string', 'null'] : 'string', 'format' => 'date', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'];
    }

    private static function datetime(bool $nullable = false): array
    {
        return ['type' => $nullable ? ['string', 'null'] : 'string', 'format' => 'date-time'];
    }

    private static function nullable(array $schema): array
    {
        return ['anyOf' => [$schema, ['type' => 'null']]];
    }

    private static function array(array $items, ?int $max = null, int $min = 0, bool $unique = false): array
    {
        $schema = ['type' => 'array', 'items' => $items, 'minItems' => $min];
        if ($max !== null) {
            $schema['maxItems'] = $max;
        }
        if ($unique) {
            $schema['uniqueItems'] = true;
        }

        return $schema;
    }

    private static function ref(string $name): array
    {
        return ['$ref' => '#/components/schemas/'.$name];
    }
}
