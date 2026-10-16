<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LegacyImportService;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ImportLegacy extends Command
{
    protected $signature = 'skladex:legacy-import {file : Private UTF-8 JSON export} {--apply : Apply the reviewed dataset in one transaction} {--actor= : Existing active administrator ID, required with --apply}';

    protected $description = 'Check a legacy Skladex export; writes require explicit --apply and an administrator';

    public function handle(LegacyImportService $service): int
    {
        $path = $this->argument('file');
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 32 * 1024 * 1024) {
            $this->error('Нужен доступный JSON-файл размером не более 32 МиБ вне публичного каталога.');

            return self::FAILURE;
        }
        $real = realpath($path);
        $public = realpath(public_path());
        if ($real === false || ($public && str_starts_with($real, $public.DIRECTORY_SEPARATOR))) {
            $this->error('Экспорт должен храниться вне публичного каталога.');

            return self::FAILURE;
        }
        try {
            $dataset = json_decode(file_get_contents($real), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->error('Файл не является корректным UTF-8 JSON.');

            return self::FAILURE;
        }
        if (! is_array($dataset)) {
            $this->error('Ожидается объект экспорта Skladex.');

            return self::FAILURE;
        }
        try {
            if ($this->option('apply')) {
                $id = (string) $this->option('actor');
                $actor = ctype_digit($id) ? User::find($id) : null;
                if (! $actor || ! $actor->is_active || $actor->role !== 'admin') {
                    $this->error('--apply требует --actor с ID существующего активного администратора.');

                    return self::FAILURE;
                }
                $result = $service->apply($actor, $dataset);
            } else {
                $result = $service->plan($dataset);
            }
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return ($result['can_apply'] ?? true) ? self::SUCCESS : self::FAILURE;
        } catch (QueryException $e) {
            // SQL exception messages can contain source values. Keep them out of
            // console transcripts; the transaction rolls back before returning.
            $this->error('Ошибка БД при проверке/переносе. Транзакция отменена; проверьте схему и ограничения.');
        } catch (HttpExceptionInterface $e) {
            $this->error($e->getMessage() ?: 'Проверка переноса не пройдена.');
        } catch (ValidationException $e) {
            $this->error('Структура экспорта не прошла проверку: '.implode(', ', array_keys($e->errors())));
        }

        return self::FAILURE;
    }
}
