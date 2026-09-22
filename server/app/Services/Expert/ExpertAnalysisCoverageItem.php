<?php

declare(strict_types=1);

namespace App\Services\Expert;

final readonly class ExpertAnalysisCoverageItem
{
    public function __construct(
        public string $materialId,
        public string $name,
        public string $status,
        public string $processingStrategy,
        public ?string $errorCode = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'material_id' => $this->materialId,
            'name' => $this->name,
            'status' => $this->status,
            'processing_strategy' => $this->processingStrategy,
            'error_code' => $this->errorCode,
        ];
    }
}
