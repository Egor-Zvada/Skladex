<?php

namespace App\Http\Controllers;

use App\Services\DirectorySettingsService;
use App\Services\DirectoryUnavailable;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DirectorySettingsController extends Controller
{
    public function edit(Request $request, DirectorySettingsService $settings)
    {
        Access::check($request->user(), 'configure');

        return response()->view('settings.directory', [
            'directory' => $settings->forForm(),
            'canEdit' => $request->user()->auth_provider === 'local',
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, DirectorySettingsService $settings)
    {
        try {
            $record = $settings->save($request->user(), $request->all());
        } catch (DirectoryUnavailable $e) {
            throw ValidationException::withMessages(['hosts' => 'Подключение недоступно. Настройки не сохранены; локальный вход продолжает работать.']);
        }
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'version' => $record->version])->header('Cache-Control', 'private, no-store');
        }

        return redirect()->route('settings.directory.edit')->with('success', 'Настройки Active Directory сохранены. Прежние доменные сеансы и токены отозваны.');
    }

    public function check(Request $request, DirectorySettingsService $settings)
    {
        try {
            $checked = $settings->probe($request->user(), $request->all());
        } catch (DirectoryUnavailable $e) {
            $checked = ['ok' => false, 'controllers' => [], 'groups' => []];
        }
        // Only an explicit diagnostic allowlist can leave the gateway.
        $safe = [
            'ok' => (bool) ($checked['ok'] ?? false),
            'controllers' => array_map(fn ($item) => ['host' => (string) $item['host'], 'ok' => (bool) $item['ok']], $checked['controllers'] ?? []),
            'message' => ($checked['ok'] ?? false)
                ? 'Соединение и группы проверены. Изменения ещё не сохранены. Если вводили новый служебный пароль, для сохранения введите его повторно.'
                : 'Не удалось проверить все необходимые параметры. Проверьте контроллеры, CA, служебную учётную запись и DN групп.',
        ];
        if ($request->expectsJson()) {
            return response()->json($safe)->header('Cache-Control', 'private, no-store');
        }

        return redirect()->route('settings.directory.edit')->with('directory_check', $safe)
            ->withInput($request->only(['expected_version', 'enabled', 'hosts', 'mode', 'port', 'base_dn', 'bind_dn', 'netbios', 'groups']));
    }
}
