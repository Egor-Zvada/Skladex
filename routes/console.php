<?php

use App\Services\OperationsService;
use App\Support\LicenseKeyCipher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schedule;

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
