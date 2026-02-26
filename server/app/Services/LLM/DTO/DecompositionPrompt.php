<?php

declare(strict_types=1);

namespace App\Services\LLM\DTO;

/**
 * DTO для промпта декомпозиции работы
 */
final class DecompositionPrompt
{
    public function __construct(
        public readonly string $title,
        public readonly array $hashableContext,
        public readonly ?float $desiredHours,
        public readonly int $schemaVersion,
        public readonly string $systemPrompt,
        public readonly string $userPrompt,
        public readonly string $inputHash
    ) {}

    /**
     * Создать из массива
     */
    public static function fromArray(array $data): self
    {
        return new self(
            title: $data['title'],
            hashableContext: $data['hashable_context'] ?? [],
            desiredHours: $data['desired_hours'] ?? null,
            schemaVersion: $data['schema_version'] ?? 1,
            systemPrompt: $data['system_prompt'],
            userPrompt: $data['user_prompt'],
            inputHash: $data['input_hash']
        );
    }

    /**
     * Преобразовать в массив
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'hashable_context' => $this->hashableContext,
            'desired_hours' => $this->desiredHours,
            'schema_version' => $this->schemaVersion,
            'system_prompt' => $this->systemPrompt,
            'user_prompt' => $this->userPrompt,
            'input_hash' => $this->inputHash,
        ];
    }
}
