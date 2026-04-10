<?php

declare(strict_types=1);

namespace App\Enums\Chat;

enum MessageType: string
{
    case TEXT = 'text';
    case SYSTEM = 'system';
    case FILE = 'file';
    case AI = 'ai';

    public function label(): string
    {
        return match ($this) {
            self::TEXT => 'Текст',
            self::SYSTEM => 'Системное',
            self::FILE => 'Файл',
            self::AI => 'AI',
        };
    }
}
