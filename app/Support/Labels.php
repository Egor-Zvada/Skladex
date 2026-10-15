<?php

namespace App\Support;

class Labels
{
    public static function status(?string $v): string
    {
        return ['stock' => 'На хранении', 'in_use' => 'В эксплуатации', 'repair' => 'В ремонте', 'written_off' => 'Списано', 'working' => 'Исправно', 'limited' => 'Есть ограничения', 'faulty' => 'Неисправно', 'unknown' => 'Не проверено', 'unspecified' => 'Не определено', 'none' => 'Не применяется', 'reactive_only' => 'По заявке', 'scheduled' => 'Плановое ТО', 'it' => 'ИТ', 'audio' => 'Звук', 'third_party' => 'Стороннее', 'in_house' => 'Самописное', 'firmware' => 'Прошивка', 'device' => 'Устройство', 'user' => 'Пользователь', 'instance' => 'Экземпляр', 'admin' => 'Администратор', 'operator' => 'Специалист', 'viewer' => 'Наблюдатель'][$v] ?? ($v ?? '—');
    }
}
