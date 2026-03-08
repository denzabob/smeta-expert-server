<?php

namespace App\Dto;

class EvidencePipelineStatusDto
{
    public function __construct(
        public ?string $state = null,
        public ?string $stage = null,
        public ?string $stage_status = null,
        public ?string $reason_code = null,
        public array $diagnostics = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            state: isset($data['state']) ? (string) $data['state'] : null,
            stage: isset($data['stage']) ? (string) $data['stage'] : null,
            stage_status: isset($data['stage_status']) ? (string) $data['stage_status'] : null,
            reason_code: isset($data['reason_code']) ? (string) $data['reason_code'] : null,
            diagnostics: is_array($data['diagnostics'] ?? null) ? $data['diagnostics'] : [],
        );
    }

    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'stage' => $this->stage,
            'stage_status' => $this->stage_status,
            'reason_code' => $this->reason_code,
            'diagnostics' => $this->diagnostics,
        ];
    }
}

