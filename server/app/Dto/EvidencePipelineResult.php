<?php

namespace App\Dto;

use App\Evidence\EvidenceItemState;
use App\Evidence\EvidenceStage;

/**
 * Standardised result of a single EvidencePipelineService run.
 * Designed to be consumed by Jobs, Controllers, and future API responses.
 */
class EvidencePipelineResult
{
    public function __construct(
        public readonly bool   $success,
        public readonly string $state,
        public readonly string $stage,
        public readonly ?string $reasonCode = null,
        public readonly ?int    $artifactId = null,
        public readonly ?int    $priceHistoryId = null,
        public readonly ?float  $extractedPrice = null,
        public readonly ?string $screenshotPath = null,
        public readonly ?string $currency = null,
        public readonly array   $diagnostics = [],
    ) {}

    /* ---- Factory helpers ---- */

    public static function ok(
        int    $artifactId,
        int    $priceHistoryId,
        float  $extractedPrice,
        string $screenshotPath,
        string $currency = 'RUB',
        array  $diagnostics = [],
    ): self {
        return new self(
            success:        true,
            state:          EvidenceItemState::AUTO_VERIFIED,
            stage:          EvidenceStage::DONE,
            artifactId:     $artifactId,
            priceHistoryId: $priceHistoryId,
            extractedPrice: $extractedPrice,
            screenshotPath: $screenshotPath,
            currency:       $currency,
            diagnostics:    $diagnostics,
        );
    }

    public static function manualRequired(
        string  $stage,
        string  $reasonCode,
        array   $diagnostics = [],
    ): self {
        return new self(
            success:    false,
            state:      EvidenceItemState::MANUAL_REQUIRED,
            stage:      $stage,
            reasonCode: $reasonCode,
            diagnostics: $diagnostics,
        );
    }

    public static function skipped(string $message, array $diagnostics = []): self
    {
        return new self(
            success:     true,
            state:       EvidenceItemState::PENDING,
            stage:       EvidenceStage::INIT,
            diagnostics: array_merge($diagnostics, ['skip_reason' => $message]),
        );
    }

    public function toArray(): array
    {
        return [
            'success'          => $this->success,
            'state'            => $this->state,
            'stage'            => $this->stage,
            'reason_code'      => $this->reasonCode,
            'artifact_id'      => $this->artifactId,
            'price_history_id' => $this->priceHistoryId,
            'extracted_price'  => $this->extractedPrice,
            'screenshot_path'  => $this->screenshotPath,
            'currency'         => $this->currency,
            'diagnostics'      => $this->diagnostics,
        ];
    }
}
