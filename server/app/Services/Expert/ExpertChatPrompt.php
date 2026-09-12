<?php

declare(strict_types=1);

namespace App\Services\Expert;

final class ExpertChatPrompt
{
    public function systemMessage(): string
    {
        return 'Ты помощник внутри экспертного проекта. Отвечай на основе текущего диалога. Не утверждай, что изучил материалы проекта, если они не были переданы тебе. Не выдумывай содержимое файлов.';
    }
}
