<?php

declare(strict_types=1);

namespace App\Services\Expert;

use RuntimeException;

final class ExpertModelPolicyException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message = 'Не удалось подготовить подходящий режим AI. Повторите запрос.',
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function profileUnavailable(string $mode): self
    {
        return new self('expert_mode_unavailable', 'Выбранный режим AI сейчас недоступен. Повторите запрос позже.');
    }

    public static function capabilityUnavailable(): self
    {
        return new self('expert_capability_unavailable', 'Не удалось обработать вложенный материал. Повторите запрос.');
    }
}
