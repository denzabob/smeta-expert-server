<?php

namespace App\Domain\PriceIndices\Http\Resources;

use App\Domain\PriceIndices\Domain\Enums\StatisticalImportPreviewStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StatisticalImportPreviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $metadata = $this->metadata_json ?? [];
        $hasProgress = isset($metadata['current_sheet'])
            || isset($metadata['current_row'])
            || isset($metadata['progress_percent']);

        return [
            'public_id' => $this->public_id,
            'status' => $this->status->value,
            'dataset' => $this->whenLoaded('dataset', fn () => [
                'public_id' => $this->dataset->public_id,
                'code' => $this->dataset->code,
                'name' => $this->dataset->name,
            ]),
            'source_file' => $this->whenLoaded('sourceFile', fn () => [
                'public_id' => $this->sourceFile->public_id,
                'original_filename' => $this->sourceFile->original_filename,
                'sha256' => $this->sourceFile->sha256,
            ]),
            'importer' => [
                'code' => $this->importer_code,
                'version' => $this->importer_version,
            ],
            'timestamps' => [
                'created_at' => $this->created_at?->toIso8601String(),
                'started_at' => $this->started_at?->toIso8601String(),
                'finished_at' => $this->finished_at?->toIso8601String(),
                'failed_at' => $this->failed_at?->toIso8601String(),
                'expires_at' => $this->expires_at?->toIso8601String(),
            ],
            'counters' => [
                'sheets_total' => $this->sheets_total,
                'supported_sheets' => $this->supported_sheets,
                'ignored_sheets' => $this->ignored_sheets,
                'commodity_occurrences' => $this->commodity_occurrences,
                'unique_classifier_items' => $this->unique_classifier_items,
                'observation_candidates' => $this->observation_candidates,
                'numeric_count' => $this->numeric_count,
                'missing_count' => $this->missing_count,
                'footnoted_count' => $this->footnoted_count,
                'warnings_count' => $this->warnings_count,
                'fatal_errors_count' => $this->fatal_errors_count,
            ],
            'progress' => $this->when($hasProgress, [
                'current_sheet' => $metadata['current_sheet'] ?? null,
                'current_row' => $metadata['current_row'] ?? null,
                'percent' => $metadata['progress_percent'] ?? null,
            ]),
            'failure' => $this->when($this->status === StatisticalImportPreviewStatus::Failed, [
                'code' => $this->failure_code,
                'message' => $this->failure_message,
            ]),
            'actions' => [
                'can_get_result' => $this->status === StatisticalImportPreviewStatus::Ready,
                'can_retry' => in_array($this->status, [
                    StatisticalImportPreviewStatus::Failed,
                    StatisticalImportPreviewStatus::Expired,
                ], true),
            ],
        ];
    }
}
