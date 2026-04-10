<?php

declare(strict_types=1);

namespace App\Enums\Chat;

enum ParticipantRole: string
{
    case CUSTOMER = 'customer';
    case ADMIN = 'admin';
    case BOT = 'bot';
    case SYSTEM = 'system';

    public function label(): string
    {
        return match ($this) {
            self::CUSTOMER => 'Пользователь',
            self::ADMIN => 'Администратор',
            self::BOT => 'Бот',
            self::SYSTEM => 'Система',
        };
    }
}
