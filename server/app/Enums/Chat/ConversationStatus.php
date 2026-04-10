<?php

declare(strict_types=1);

namespace App\Enums\Chat;

enum ConversationStatus: string
{
    case OPEN = 'open';
    case PENDING = 'pending';
    case CLOSED = 'closed';

    // Future statuses:
    // case WAITING_FOR_USER = 'waiting_for_user';
    // case WAITING_FOR_ADMIN = 'waiting_for_admin';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Открыт',
            self::PENDING => 'Ожидание',
            self::CLOSED => 'Закрыт',
        };
    }

    /**
     * Статусы, в которых диалог считается активным.
     *
     * @return self[]
     */
    public static function activeStatuses(): array
    {
        return [self::OPEN, self::PENDING];
    }
}
