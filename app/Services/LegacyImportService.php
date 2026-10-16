<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetMovement;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\LegacyImport;
use App\Models\LegacyRecord;
use App\Models\Location;
use App\Models\QrAlias;
use App\Models\User;
use App\Support\Access;
use App\Support\Codes;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** A bounded, explicit legacy migration, never a general-purpose SQL importer. */
class LegacyImportService
{
    private const SOURCE = 'skladex-v1';

    private const TABLES = ['users', 'cabinets', 'categories', 'equipment', 'equipment_moves', 'equipment_status_history', 'attachments', 'audit_log', 'qr_codes'];

    private const STATUS = ['reserve' => 'stock', 'in_use' => 'in_use', 'repair' => 'repair', 'decommissioned' => 'written_off'];

    private const TARGETS = ['users' => User::class, 'cabinets' => Location::class, 'categories' => Category::class, 'equipment' => Asset::class, 'equipment_moves' => AssetMovement::class, 'equipment_status_history' => AuditEvent::class, 'audit_log' => AuditEvent::class];

    public function plan(array $dataset): array
    {
        $p = $this->prepare($dataset);
        $inspection = $p['conflicts'] ? ['conflicts' => [], 'records' => [], 'replay' => false] : $this->inspect($p, false);

        return [
            'dataset_sha256' => $p['hash'], 'source_counts' => $p['counts'], 'counts' => $p['counts'],
            'warnings' => $p['warnings'], 'conflicts' => array_merge($p['conflicts'], $inspection['conflicts']),
            'replay' => $inspection['replay'], 'can_apply' => ! $p['conflicts'] && ! $inspection['conflicts'],
        ];
    }

    public function apply(User $actor, array $dataset): array
    {
        Access::check($actor, 'configure');
        $p = $this->prepare($dataset);
        abort_if((bool) $p['conflicts'], 422, implode(' ', $p['conflicts']));

        try {
            return DB::transaction(function () use ($actor, $p) {
                // Settings uses the same first mutex. No asset lock may precede the graph mutex.
                foreach (['AUTH-SETTINGS', 'IMPORT-SKLADEX-V1'] as $prefix) {
                    DB::table('code_counters')->insertOrIgnore(['prefix' => $prefix, 'next_value' => 1]);
                    DB::table('code_counters')->where('prefix', $prefix)->lockForUpdate()->first();
                }
                Codes::lockPhysicalGraph();
                $freshActor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                Access::check($freshActor, 'configure');
                $previous = LegacyImport::where('source_system', self::SOURCE)->where('dataset_sha256', $p['hash'])->lockForUpdate()->first();
                if ($previous) {
                    return $this->result($previous, 'noop', $p['warnings']);
                }
                $inspection = $this->inspect($p, true);
                abort_if((bool) $inspection['conflicts'], 409, implode(' ', $inspection['conflicts']));
                $import = LegacyImport::create([
                    'uuid' => (string) Str::uuid(), 'source_system' => self::SOURCE, 'dataset_sha256' => $p['hash'],
                    'source_host' => $p['source']['host'], 'exported_at' => $this->date($p['source']['exported_at'] ?? null),
                    'applied_at' => now(), 'applied_by' => $freshActor->id, 'source_counts' => $p['counts'],
                    'result_counts' => [], 'metadata' => ['format' => 'skladex-legacy-v1', 'timezone' => 'UTC', 'warnings' => $p['warnings']],
                ]);
                $counts = array_fill_keys(['users_created', 'users_reused', 'categories_created', 'locations_created', 'assets_created', 'movements_created', 'audit_events_created', 'aliases_created', 'records_created', 'records_reused', 'qr_unresolved'], 0);
                $maps = array_fill_keys(self::TABLES, []);
                foreach ($inspection['records'] as $table => $rows) {
                    foreach ($rows as $key => $record) {
                        $maps[$table][$key] = $record->target_id;
                    }
                }
                $pending = function (string $table, array $row) use ($inspection, &$counts): bool {
                    if (isset($inspection['records'][$table][$this->key($row)])) {
                        $counts['records_reused']++;

                        return false;
                    }

                    return true;
                };
                $saveRecord = function (string $table, array $row, ?Model $target, string $type = 'History') use ($import, &$counts, &$maps): void {
                    $this->record($import, $table, $row, $target, $type);
                    $maps[$table][$this->key($row)] = $target?->getKey();
                    $counts['records_created']++;
                };
                foreach ($p['tables']['users'] as $row) {
                    if (! $pending('users', $row)) {
                        continue;
                    }
                    $user = $row['login'] === 'admin' ? User::where('username', 'admin')->lockForUpdate()->first() : null;
                    if ($user) {
                        $counts['users_reused']++;
                    } else {
                        $user = $this->create(User::class, [
                            'name' => $row['login'], 'username' => $this->username($row), 'email' => $this->email($row),
                            'password' => Str::random(80), 'role' => $row['role'], 'is_active' => false,
                            'created_at' => $this->date($row['created_at'] ?? null), 'updated_at' => $this->date($row['updated_at'] ?? null),
                        ]);
                        $counts['users_created']++;
                    }
                    $saveRecord('users', $row, $user, 'User');
                }
                foreach ($p['tables']['categories'] as $row) {
                    if (! $pending('categories', $row)) {
                        continue;
                    }
                    $category = $this->create(Category::class, [
                        'name' => $row['name'], 'slug' => $this->categorySlug($this->key($row)),
                        'domain' => $this->categoryDomain($this->key($row)), 'kind' => in_array($this->key($row), ['1', '2', '3'], true) ? 'computer' : 'standard',
                        'maintenance_policy' => 'unspecified', 'created_at' => null, 'updated_at' => null,
                    ]);
                    $counts['categories_created']++;
                    $saveRecord('categories', $row, $category, 'Category');
                }
                foreach ($p['tables']['cabinets'] as $row) {
                    if (! $pending('cabinets', $row)) {
                        continue;
                    }
                    $location = $this->create(Location::class, [
                        'code' => $row['id'], 'name' => ! empty($row['title']) ? $row['title'] : $row['id'],
                        'is_public' => true, 'version' => 1, 'created_at' => $this->date($row['created_at'] ?? null), 'updated_at' => $this->date($row['updated_at'] ?? null),
                    ]);
                    $counts['locations_created']++;
                    $saveRecord('cabinets', $row, $location, 'Location');
                }
                $uncategorized = null;
                foreach ($p['tables']['equipment'] as $row) {
                    if (! $pending('equipment', $row)) {
                        continue;
                    }
                    if (($row['category_id'] ?? null) === null && $uncategorized === null) {
                        $uncategorized = Category::where('slug', 'legacy-v1-uncategorized')->lockForUpdate()->first();
                        if (! $uncategorized) {
                            $uncategorized = $this->create(Category::class, ['name' => 'Без категории (прежний Skladex)', 'slug' => 'legacy-v1-uncategorized', 'domain' => 'unknown', 'kind' => 'standard', 'maintenance_policy' => 'unspecified', 'created_at' => null, 'updated_at' => null]);
                            $counts['categories_created']++;
                        }
                    }
                    $asset = $this->create(Asset::class, [
                        'code' => $row['code'], 'name' => $row['name'], 'inventory_no' => $row['inventory_no'],
                        'category_id' => ($row['category_id'] ?? null) === null ? $uncategorized->id : $maps['categories'][(string) $row['category_id']],
                        'location_id' => $maps['cabinets'][(string) $row['cabinet_id']], 'private_notes' => $row['description'] ?? null,
                        'status' => self::STATUS[$row['status']], 'condition' => 'unknown', 'maintenance_policy' => 'unspecified', 'version' => 1,
                        'created_at' => $this->date($row['created_at'] ?? null), 'updated_at' => $this->date($row['updated_at'] ?? null),
                    ]);
                    $counts['assets_created']++;
                    $saveRecord('equipment', $row, $asset, 'Asset');
                }
                $aliases = [];
                foreach ($p['aliases'] as $alias => $target) {
                    $id = $maps[$target['table']][$target['key']];
                    $existing = QrAlias::where('alias', $alias)->lockForUpdate()->first();
                    abort_if($existing && ($existing->target_type !== $target['type'] || (int) $existing->target_id !== (int) $id), 409, 'Алиас уже связан с другой целью.');
                    if (! $existing) {
                        $existing = QrAlias::create(['alias' => $alias, 'target_type' => $target['type'], 'target_id' => $id, 'created_at' => now()]);
                        $counts['aliases_created']++;
                    }
                    $aliases[$alias] = $existing;
                }
                foreach ($p['tables']['equipment_moves'] as $row) {
                    if (! $pending('equipment_moves', $row)) {
                        continue;
                    }
                    // Import facts, do not replay a move command against today's placement.
                    $movement = $this->create(AssetMovement::class, [
                        'asset_id' => $maps['equipment'][(string) $row['equipment_id']],
                        'from_location_id' => ($row['from_cabinet_id'] ?? null) === null ? null : $maps['cabinets'][(string) $row['from_cabinet_id']],
                        'to_location_id' => $maps['cabinets'][(string) $row['to_cabinet_id']],
                        'from_status' => null, 'to_status' => null, 'reason' => $row['note'] ?? 'Историческое перемещение: причина не записана.',
                        'actor_id' => ($row['moved_by'] ?? null) === null ? null : $maps['users'][(string) $row['moved_by']],
                        'asset_version' => null, 'occurred_at' => $this->date($row['moved_at'] ?? null),
                        'created_at' => $this->date($row['moved_at'] ?? null), 'updated_at' => null,
                    ]);
                    $counts['movements_created']++;
                    $saveRecord('equipment_moves', $row, $movement, 'AssetMovement');
                }
                foreach ($p['tables']['audit_log'] as $row) {
                    if (! $pending('audit_log', $row)) {
                        continue;
                    }
                    $sourceTable = ['equipment' => 'equipment', 'cabinets' => 'cabinets', 'categories' => 'categories', 'users' => 'users'][$row['entity']] ?? null;
                    $id = $sourceTable ? ($maps[$sourceTable][(string) $row['entity_id']] ?? null) : null;
                    $model = $id && $sourceTable ? self::TARGETS[$sourceTable]::findOrFail($id) : null;
                    $event = $this->create(AuditEvent::class, [
                        'actor_id' => null, 'event' => 'legacy.'.$row['entity'].'.'.$row['action'],
                        'subject_type' => $model ? class_basename($model) : ($row['entity'] === 'equipment' ? 'Asset' : 'History'),
                        'subject_id' => $model?->id, 'subject_uuid' => $model?->uuid,
                        'data' => ['source_system' => self::SOURCE, 'legacy_audit_id' => $row['id'], 'legacy_entity_id' => (string) $row['entity_id'], 'original_snapshot' => $row['snapshot'] ?? null, 'import_uuid' => $import->uuid, 'source_timestamp_unknown' => empty($row['created_at'])],
                        'created_at' => $this->date($row['created_at'] ?? null) ?? now(),
                    ]);
                    $counts['audit_events_created']++;
                    $saveRecord('audit_log', $row, $event, 'AuditEvent');
                }
                foreach ($p['tables']['equipment_status_history'] as $row) {
                    if (! $pending('equipment_status_history', $row)) {
                        continue;
                    }
                    $asset = Asset::findOrFail($maps['equipment'][(string) $row['equipment_id']]);
                    $event = $this->create(AuditEvent::class, [
                        'actor_id' => ($row['changed_by'] ?? null) === null ? null : $maps['users'][(string) $row['changed_by']],
                        'event' => 'legacy.status', 'subject_type' => 'Asset', 'subject_id' => $asset->id, 'subject_uuid' => $asset->uuid,
                        'data' => ['source_system' => self::SOURCE, 'legacy_status_id' => $row['id'], 'legacy_entity_id' => (string) $row['equipment_id'], 'original_snapshot' => $row, 'import_uuid' => $import->uuid, 'source_timestamp_unknown' => empty($row['changed_at'])],
                        'created_at' => $this->date($row['changed_at'] ?? null) ?? now(),
                    ]);
                    $counts['audit_events_created']++;
                    $saveRecord('equipment_status_history', $row, $event, 'AuditEvent');
                }
                foreach ($p['tables']['qr_codes'] as $row) {
                    if (! $pending('qr_codes', $row)) {
                        continue;
                    }
                    $qrAliases = $p['qr_aliases'][$this->key($row)] ?? [];
                    $alias = $qrAliases ? ($aliases[$qrAliases[0]] ?? null) : null;
                    if (! $alias) {
                        $counts['qr_unresolved']++;
                    }
                    $saveRecord('qr_codes', $row, $alias, $alias ? 'QrAlias' : 'History');
                }
                $import->result_counts = $counts;
                $import->metadata = ['format' => 'skladex-legacy-v1', 'timezone' => 'UTC', 'warnings' => $p['warnings'], 'uncategorized_category_id' => $uncategorized?->id];
                $import->save();

                return $this->result($import, 'applied', $p['warnings']);
            }, 3);
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23000') {
                abort(409, 'Данные назначения изменились или нарушено соответствие. Импорт полностью отменён.');
            }
            throw $e;
        }
    }

    private function prepare(array $dataset): array
    {
        $errors = [];
        $warnings = [];
        $tables = array_fill_keys(self::TABLES, []);
        $validation = Validator::make($dataset, ['format' => 'required|in:skladex-legacy-v1', 'source' => 'required|array', 'source.system' => 'required|in:skladex-v1', 'source.host' => 'required|string|max:255', 'source.timezone' => 'required|in:UTC', 'source.exported_at' => 'nullable|date', 'tables' => 'required|array']);
        foreach ($validation->errors()->keys() as $field) {
            $errors[] = 'Некорректное поле набора: '.$field.'.';
        }
        $sourceTables = is_array($dataset['tables'] ?? null) ? $dataset['tables'] : [];
        foreach (array_diff(array_keys($sourceTables), self::TABLES) as $table) {
            $errors[] = 'Неподдерживаемая таблица: '.$table.'.';
        }
        $schemas = $this->schemas();
        $index = array_fill_keys(self::TABLES, []);
        foreach (self::TABLES as $table) {
            if (! array_key_exists($table, $sourceTables) || ! is_array($sourceTables[$table]) || ! array_is_list($sourceTables[$table])) {
                $errors[] = 'Таблица '.$table.' должна быть полным списком строк.';

                continue;
            }
            foreach ($sourceTables[$table] as $position => $raw) {
                if (! is_array($raw) || array_is_list($raw)) {
                    $errors[] = 'Некорректная строка '.$table.' #'.$position.'.';

                    continue;
                }
                $row = $this->withoutCredentials($raw);
                $v = Validator::make($row, $schemas[$table]);
                $key = $this->key($row);
                if ($v->fails() || ! $this->validKey($key, $table === 'cabinets')) {
                    $errors[] = 'Некорректные поля строки '.$table.' #'.$position.'.';

                    continue;
                }
                if (isset($index[$table][$key])) {
                    $errors[] = 'Повтор исходного PK в '.$table.'.';

                    continue;
                }
                if ($table === 'audit_log' && isset($row['snapshot']) && ! is_array($row['snapshot']) && ! $this->validJson($row['snapshot'])) {
                    $errors[] = 'Некорректный snapshot аудита #'.$key.'.';
                }
                if ($table === 'audit_log' && ((! is_string($row['entity_id']) && ! is_int($row['entity_id'])) || strlen((string) $row['entity_id']) > 64)) {
                    $errors[] = 'Некорректный исходный идентификатор аудита #'.$key.'.';

                    continue;
                }
                if ($table === 'categories' && ! in_array($key, array_map('strval', range(1, 13)), true)) {
                    $errors[] = 'Для исходной категории #'.$key.' не задана предметная область.';
                }
                if ($table === 'equipment' && strlen((string) ($row['description'] ?? '')) > 65535) {
                    $errors[] = 'Описание не помещается в TEXT; усечение запрещено.';
                }
                $index[$table][$key] = $row;
                $tables[$table][] = $row;
            }
            usort($tables[$table], fn ($a, $b) => strcmp($this->key($a), $this->key($b)));
        }
        if ($tables['attachments']) {
            $errors[] = 'Вложения требуют отдельного подтверждённого копирования байтов; импорт attachments пока не поддержан.';
        }
        foreach ($tables['equipment'] as $row) {
            $this->reference($index, 'cabinets', $row['cabinet_id'], false, $errors);
            $this->reference($index, 'categories', $row['category_id'] ?? null, true, $errors);
            foreach (['created_by', 'updated_by'] as $field) {
                $this->reference($index, 'users', $row[$field] ?? null, true, $errors);
            }
        }
        foreach ($tables['equipment_moves'] as $row) {
            $this->reference($index, 'equipment', $row['equipment_id'], false, $errors);
            $this->reference($index, 'cabinets', $row['from_cabinet_id'] ?? null, true, $errors);
            $this->reference($index, 'cabinets', $row['to_cabinet_id'], false, $errors);
            $this->reference($index, 'users', $row['moved_by'] ?? null, true, $errors);
        }
        foreach ($tables['equipment_status_history'] as $row) {
            $this->reference($index, 'equipment', $row['equipment_id'], false, $errors);
            $this->reference($index, 'users', $row['changed_by'] ?? null, true, $errors);
        }
        $aliases = [];
        $codes = [];
        foreach ($tables['equipment'] as $row) {
            $key = $this->key($row);
            $code = $this->code($row['code']);
            if (isset($codes[$code])) {
                $errors[] = 'Повтор сохранённого кода предмета в источнике.';
            }
            $codes[$code] = $key;
            $this->addAlias($aliases, 'asset-code:'.$code, ['table' => 'equipment', 'key' => $key, 'type' => 'asset'], $errors);
            $this->addAlias($aliases, 'asset-id:'.$key, ['table' => 'equipment', 'key' => $key, 'type' => 'asset'], $errors);
        }
        foreach ($tables['cabinets'] as $row) {
            $key = $this->key($row);
            if (isset($codes[$this->code($key)])) {
                $errors[] = 'Код кабинета пересекается с кодом предмета.';
            }
            $this->addAlias($aliases, 'location-id:'.$key, ['table' => 'cabinets', 'key' => $key, 'type' => 'location'], $errors);
        }
        $qrAliases = [];
        foreach ($tables['qr_codes'] as $row) {
            $key = $this->key($row);
            $this->reference($index, 'users', $row['created_by'] ?? null, true, $errors);
            $target = $row['target_type'] === 'equipment'
                ? (isset($codes[$this->code($row['target_code'])]) ? ['table' => 'equipment', 'key' => $codes[$this->code($row['target_code'])], 'type' => 'asset'] : null)
                : (isset($index['cabinets'][$row['target_code']]) ? ['table' => 'cabinets', 'key' => $row['target_code'], 'type' => 'location'] : null);
            $contentTarget = $this->qrTarget($row['content'], $index, $codes);
            if (! $row['is_active'] || ! $target || $contentTarget !== $target) {
                $warnings[] = 'QR #'.$key.' сохранён как неактивный/несопоставленный источник; новая цель не создаётся.';

                continue;
            }
            $names = [];
            $url = parse_url($row['content']);
            if (is_array($url) && isset($url['scheme'], $url['host']) && in_array(strtolower($url['scheme']), ['http', 'https'], true)) {
                $names[] = 'url:'.$row['content'];
                $names[] = 'path:'.($url['path'] ?? '/').(isset($url['query']) ? '?'.$url['query'] : '');
            } elseif (str_starts_with($row['content'], '/')) {
                $names[] = 'path:'.$row['content'];
            } else {
                $names[] = ($target['type'] === 'asset' ? 'asset-code:'.$this->code($row['target_code']) : 'location-id:'.$row['target_code']);
            }
            if (array_filter($names, fn ($name) => mb_strlen($name) > 255 || preg_match('/[\x00-\x1f\x7f]/', $name))) {
                $warnings[] = 'QR #'.$key.' сохранён без активации: исходная ссылка не помещается в алиас.';

                continue;
            }
            foreach (array_unique($names) as $alias) {
                $this->addAlias($aliases, $alias, $target, $errors);
            }
            $qrAliases[$key] = $names;
        }
        if (array_filter($tables['audit_log'], fn ($r) => $r['entity'] === 'equipment' && ! isset($index['equipment'][(string) $r['entity_id']]))) {
            $warnings[] = 'История удалённых предметов сохраняется с пустой новой целью и исходным идентификатором.';
        }
        $counts = array_map('count', $tables);

        return ['source' => is_array($dataset['source'] ?? null) ? $dataset['source'] : [], 'tables' => $tables, 'index' => $index, 'aliases' => $aliases, 'qr_aliases' => $qrAliases, 'counts' => $counts, 'hash' => $this->hash($tables), 'conflicts' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
    }

    private function inspect(array $p, bool $lock): array
    {
        $query = LegacyRecord::where('source_system', self::SOURCE);
        if ($lock) {
            $query->lockForUpdate();
        }
        $records = array_fill_keys(self::TABLES, []);
        foreach ($query->get() as $record) {
            $records[$record->table_name][$record->source_key] = $record;
        }
        $conflicts = [];
        foreach ($p['tables'] as $table => $rows) {
            foreach ($rows as $row) {
                $key = $this->key($row);
                $old = $records[$table][$key] ?? null;
                if ($old && ! hash_equals($old->source_sha256, $this->hash($row))) {
                    $conflicts[] = 'Изменилась ранее перенесённая строка '.$table.' #'.$key.'; перезапись запрещена.';
                }
                if ($old && isset(self::TARGETS[$table]) && ($old->target_type !== class_basename(self::TARGETS[$table]) || ! $old->target_id || ! $this->query(self::TARGETS[$table], $lock)->whereKey($old->target_id)->first())) {
                    $conflicts[] = 'Утрачена цель ранее перенесённой строки '.$table.' #'.$key.'.';
                }
                if ($old) {
                    continue;
                }
                if ($table === 'equipment' || $table === 'cabinets') {
                    $code = $table === 'equipment' ? $row['code'] : $row['id'];
                    foreach ([Asset::class, Location::class] as $class) {
                        if ($this->query($class, $lock)->where('code', $code)->first()) {
                            $conflicts[] = 'Код из '.$table.' уже занят вне соответствия источника.';
                        }
                    }
                } elseif ($table === 'categories' && $this->query(Category::class, $lock)->where('slug', $this->categorySlug($key))->first()) {
                    $conflicts[] = 'Служебный slug категории уже занят вне соответствия источника.';
                } elseif ($table === 'users') {
                    $existing = $this->query(User::class, $lock)->where('username', $this->username($row))->first();
                    if (($existing && $row['login'] !== 'admin') || (! $existing && $this->query(User::class, $lock)->where('email', $this->email($row))->first())) {
                        $conflicts[] = 'Логин/служебная почта исторического пользователя уже заняты.';
                    }
                }
            }
        }
        foreach ($p['aliases'] as $alias => $target) {
            $existing = $this->query(QrAlias::class, $lock)->where('alias', $alias)->first();
            $record = $records[$target['table']][$target['key']] ?? null;
            if ($existing && (! $record || $existing->target_type !== $target['type'] || (int) $existing->target_id !== (int) $record->target_id)) {
                $conflicts[] = 'Алиас назначения уже занят другой целью.';
            }
        }
        $needsUnknown = array_filter($p['tables']['equipment'], fn ($row) => ($row['category_id'] ?? null) === null && ! isset($records['equipment'][$this->key($row)]));
        if ($needsUnknown && ($unknown = $this->query(Category::class, $lock)->where('slug', 'legacy-v1-uncategorized')->first())) {
            $owner = $this->query(LegacyImport::class, $lock)->where('source_system', self::SOURCE)->where('metadata->uncategorized_category_id', $unknown->id)->first();
            if (! $owner) {
                $conflicts[] = 'Служебная категория неизвестного типа уже занята вне импорта.';
            }
        }
        $replay = LegacyImport::where('source_system', self::SOURCE)->where('dataset_sha256', $p['hash'])->exists();

        return ['records' => $records, 'conflicts' => array_values(array_unique($conflicts)), 'replay' => $replay];
    }

    private function schemas(): array
    {
        $date = ['created_at' => 'nullable|date', 'updated_at' => 'nullable|date'];
        $id = ['id' => 'required'];
        $status = 'in:in_use,repair,reserve,decommissioned';

        return [
            'users' => $id + ['login' => 'required|string|max:60', 'role' => 'required|in:admin,operator,viewer', 'is_active' => 'required|boolean'] + $date,
            'cabinets' => ['id' => ['required', 'string', 'max:32', 'not_regex:/[\p{Cc}\p{Cf}]/u'], 'title' => 'nullable|string|max:200'] + $date,
            'categories' => $id + ['name' => 'required|string|max:120'],
            'equipment' => $id + ['code' => ['required', 'string', 'max:64', 'not_regex:/[\p{Cc}\p{Cf}]/u'], 'name' => 'required|string|max:255', 'inventory_no' => 'present|nullable|string|max:120', 'description' => 'nullable|string', 'status' => 'required|'.$status, 'cabinet_id' => 'required|string|max:32'] + $date,
            'equipment_moves' => $id + ['equipment_id' => 'required', 'to_cabinet_id' => 'required|string|max:32', 'from_cabinet_id' => 'nullable|string|max:32', 'note' => 'nullable|string|max:255', 'moved_at' => 'nullable|date'],
            'equipment_status_history' => $id + ['equipment_id' => 'required', 'old_status' => 'nullable|'.$status, 'new_status' => 'required|'.$status, 'note' => 'nullable|string|max:255', 'changed_at' => 'nullable|date'],
            'attachments' => $id,
            'audit_log' => $id + ['entity' => 'required|string|max:64|regex:/\A[a-zA-Z0-9_]+\z/', 'entity_id' => 'required', 'action' => 'required|in:insert,update,delete', 'created_at' => 'nullable|date'],
            'qr_codes' => $id + ['target_type' => 'required|in:cabinet,equipment', 'target_code' => 'required|string|max:64', 'purpose' => 'required|in:card,label', 'content' => 'required|string|max:65535', 'is_active' => 'required|boolean', 'created_at' => 'nullable|date'],
        ];
    }

    private function qrTarget(string $input, array $index, array $codes): ?array
    {
        $value = trim($input);
        if (preg_match('~^https?://~i', $value)) {
            $url = parse_url($value);
            if (! $url || isset($url['user']) || isset($url['pass'])) {
                return null;
            }
            $value = ($url['path'] ?? '/').(isset($url['query']) ? '?'.$url['query'] : '');
        }
        $asset = fn ($code) => $this->qrAssetTarget($code, $index, $codes);
        $room = fn ($key) => isset($index['cabinets'][$key]) ? ['table' => 'cabinets', 'key' => $key, 'type' => 'location'] : null;
        if (preg_match('~^/eq/([^/?#]+)/?$~u', $value, $m)) {
            return $asset(rawurldecode($m[1]));
        }
        if (preg_match('~^/cabinet/([^/?#]+)/?$~u', $value, $m)) {
            return $room(rawurldecode($m[1]));
        }
        if (str_starts_with($value, '/cabinet.php?')) {
            parse_str(parse_url($value, PHP_URL_QUERY) ?? '', $query);

            return isset($query['id']) && is_string($query['id']) ? $room($query['id']) : null;
        }
        if (str_starts_with($value, '/equipment_card.php?') || str_starts_with($value, '/admin/equipment.php?')) {
            parse_str(parse_url($value, PHP_URL_QUERY) ?? '', $query);
            $code = $query['code'] ?? $query['key'] ?? $query['id'] ?? null;
            if (! is_string($code)) {
                return null;
            }

            return $asset($code);
        }
        if (ctype_digit($value)) {
            return $asset($value);
        }

        return $asset($value) ?? $room($value);
    }

    private function qrAssetTarget(string $value, array $index, array $codes): ?array
    {
        $value = trim($value);
        if (ctype_digit($value)) {
            // Old PHP cast numeric equipment IDs to int. EQ padding and room IDs are untouched.
            $key = ltrim($value, '0');

            return $key !== '' && isset($index['equipment'][$key]) ? ['table' => 'equipment', 'key' => $key, 'type' => 'asset'] : null;
        }
        $code = $this->code($value);

        return isset($codes[$code]) ? ['table' => 'equipment', 'key' => $codes[$code], 'type' => 'asset'] : null;
    }

    private function addAlias(array &$aliases, string $alias, array $target, array &$errors): void
    {
        if (mb_strlen($alias) > 255 || preg_match('/[\x00-\x1f\x7f]/', $alias)) {
            $errors[] = 'Исторический алиас не помещается в допустимое поле.';
        } elseif (isset($aliases[$alias]) && $aliases[$alias] !== $target) {
            $errors[] = 'Неоднозначный исторический алиас.';
        } else {
            $aliases[$alias] = $target;
        }
    }

    private function reference(array $index, string $table, mixed $key, bool $nullable, array &$errors): void
    {
        if ($key === null && $nullable) {
            return;
        }
        if ((! is_string($key) && ! is_int($key)) || ! isset($index[$table][(string) $key])) {
            $errors[] = 'Отсутствует исходная связанная строка '.$table.'.';
        }
    }

    private function record(LegacyImport $import, string $table, array $row, ?Model $target, string $type): void
    {
        LegacyRecord::create(['import_id' => $import->id, 'source_system' => self::SOURCE, 'table_name' => $table, 'source_key' => $this->key($row), 'target_type' => $type, 'target_id' => $target?->getKey(), 'target_uuid' => $target?->uuid, 'payload' => $row, 'source_sha256' => $this->hash($row), 'created_at' => now()]);
    }

    private function create(string $class, array $attributes): Model
    {
        $model = new $class;
        $model->timestamps = false;
        $model->fill($attributes)->save();

        return $model;
    }

    private function query(string $class, bool $lock)
    {
        $query = $class::query();

        return $lock ? $query->lockForUpdate() : $query;
    }

    private function result(LegacyImport $import, string $status, array $warnings): array
    {
        return ['status' => $status, 'import_id' => $import->id, 'import_uuid' => $import->uuid, 'dataset_sha256' => $import->dataset_sha256, 'source_counts' => $import->source_counts, 'result_counts' => $import->result_counts, 'warnings' => $warnings];
    }

    private function categorySlug(string $key): string
    {
        return 'legacy-v1-category-'.$key;
    }

    private function categoryDomain(string $key): string
    {
        return match ($key) {
            '11' => 'audio', '12' => 'stage', default => 'it'
        };
    }

    private function username(array $row): string
    {
        return preg_match('/\A[a-z0-9._-]{3,64}\z/', $row['login']) ? $row['login'] : 'legacy-'.$this->key($row);
    }

    private function email(array $row): string
    {
        return 'legacy-user-'.$this->key($row).'@skladex.invalid';
    }

    private function key(array $row): string
    {
        return is_string($row['id'] ?? null) || is_int($row['id'] ?? null) ? (string) $row['id'] : '';
    }

    private function validKey(string $key, bool $string): bool
    {
        return $string ? $key !== '' && mb_strlen($key) <= 32 : (bool) preg_match('/\A[1-9][0-9]{0,19}\z/', $key);
    }

    private function code(string $value): string
    {
        return preg_match('/\AEQ-[0-9]+\z/i', $value) ? strtoupper($value) : $value;
    }

    private function date(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : CarbonImmutable::parse($value, 'UTC')->utc()->format('Y-m-d H:i:s.u');
    }

    private function validJson(mixed $value): bool
    {
        return is_string($value) && json_validate($value);
    }

    private function withoutCredentials(array $data): array
    {
        $safe = [];
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), ['password', 'password_hash', 'passwd', 'remember_token'], true)) {
                continue;
            }
            $safe[$key] = is_array($value) ? $this->withoutCredentials($value) : $value;
        }

        return $safe;
    }

    private function hash(array $data): string
    {
        $sort = function (array $value) use (&$sort): array {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $sort($item);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($sort($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }
}
