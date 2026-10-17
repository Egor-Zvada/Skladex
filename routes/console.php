<?php

use App\Models\Asset;
use App\Models\User;
use App\Services\DirectoryGateway;
use App\Services\DirectoryUnavailable;
use App\Services\MaintenancePresetService;
use App\Services\OperationsService;
use App\Support\LicenseKeyCipher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schedule;

Artisan::command('skladex:directory-check', function () {
    try {
        $results = app(DirectoryGateway::class)->checkConnection();
    } catch (DirectoryUnavailable $e) {
        $this->error('Защищённые настройки LDAP недоступны или некорректны.');

        return 1;
    }
    foreach ($results as $result) {
        $this->line($result['host'].': '.($result['ok'] ? 'TLS / bind / read: OK' : 'FAIL'));
    }

    return collect($results)->every('ok') ? 0 : 1;
})->purpose('Read-only TLS and service-bind verification for each configured directory controller');

Artisan::command('skladex:maintenance-presets {--apply : Apply the reviewed recommendations} {--actor= : Active local administrator ID}', function () {
    $service = app(MaintenancePresetService::class);
    $assets = Asset::with('category')->whereNull('archived_at')->where('status', '!=', 'written_off')->orderBy('id')->get();
    $groups = $assets->groupBy(fn ($asset) => $service->recommend($asset));
    $this->table(['Регламент', 'Предметов'], $groups->map(fn ($items, $key) => [$service->catalog()[$key]['name'], $items->count()])->values()->all());
    $this->line('Первый срок останется неназначенным; выполненные работы и состояние оборудования не изменяются.');
    if (! $this->option('apply')) {
        $this->info('Только предварительный просмотр. Для записи нужны --apply и --actor.');

        return 0;
    }
    $actor = User::whereKey($this->option('actor'))->where('auth_provider', 'local')->where('role', 'admin')->where('is_active', true)->first();
    if (! $actor) {
        $this->error('Укажите ID действующего локального администратора.');

        return 1;
    }
    Auth::setUser($actor);
    $created = $skipped = 0;
    foreach ($groups as $key => $items) {
        foreach ($items->chunk(500) as $batch) {
            $result = $service->apply($actor, ['preset_key' => $key, 'asset_uuids' => $batch->pluck('uuid')->all(), 'asset_versions' => $batch->pluck('version', 'uuid')->all()]);
            $created += $result['created'];
            $skipped += $result['skipped'];
        }
    }
    $this->info('Добавлено: '.$created.'. Сохранено ранее настроенных: '.$skipped.'.');

    return 0;
})->purpose('Preview or explicitly apply maintenance recommendations without inventing service dates');

Artisan::command('skladex:maintenance', function () {
    $this->info('Создано работ по планам: '.app(OperationsService::class)->generateDuePlans());
})->purpose('Generate due maintenance occurrences and internal reminders');

Artisan::command('skladex:check', function () {
    DB::select('SELECT 1');
    Redis::connection()->ping();
    $cipher = app(LicenseKeyCipher::class);
    $probe = bin2hex(random_bytes(24));
    if (! hash_equals($probe, $cipher->decrypt($cipher->encrypt($probe), config('skladex.license_key_id')))) {
        throw new RuntimeException('License cipher check failed');
    }
    $this->info('Database, Redis and license cipher: OK');
})->purpose('Check application dependencies without printing secrets');

Artisan::command('skladex:verify-restored-keys {keyfile}', function () {
    if (! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        throw new RuntimeException('Restore verification requires root.');
    }
    config(['skladex.license_master_key' => trim(file_get_contents($this->argument('keyfile')))]);
    $cipher = app(LicenseKeyCipher::class);
    $probe = bin2hex(random_bytes(24));
    if (! hash_equals($probe, $cipher->decrypt($cipher->encrypt($probe), config('skladex.license_key_id')))) {
        throw new RuntimeException('Invalid restored license key');
    }
    $db = config('database.connections.mariadb');
    $db['database'] = 'skladex_restore_test';
    $db['username'] = 'root';
    $db['password'] = '';
    config(['database.connections.restore_verify' => $db]);
    $count = 0;
    foreach (DB::connection('restore_verify')->table('license_secrets')->cursor() as $row) {
        $cipher->decrypt($row->ciphertext, $row->key_id);
        $count++;
    }
    $this->info('Restored license secrets verified: '.$count);
})->purpose('Verify license decryption in the isolated restore database');

Schedule::command('skladex:maintenance')->everyFiveMinutes()->withoutOverlapping(10)
    ->appendOutputTo(storage_path('logs/scheduler.log'));
Schedule::call(fn () => DB::table('idempotency_keys')->where('expires_at', '<', now())->delete())
    ->name('prune-idempotency')->daily()->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->daily()->withoutOverlapping();
