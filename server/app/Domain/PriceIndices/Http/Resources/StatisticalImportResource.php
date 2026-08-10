<?php

namespace App\Domain\PriceIndices\Http\Resources;

use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StatisticalImportResource extends JsonResource
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
            'attempt_no' => $this->attempt_no,
            'timestamps' => [
                'created_at' => $this->created_at?->toIso8601String(),
                'started_at' => $this->started_at?->toIso8601String(),
                'finished_at' => $this->finished_at?->toIso8601String(),
                'ready_at' => $this->ready_at?->toIso8601String(),
                'published_at' => $this->published_at?->toIso8601String(),
                'superseded_at' => $this->superseded_at?->toIso8601String(),
                'failed_at' => $this->failed_at?->toIso8601String(),
            ],
            'counters' => [
                'rows_scanned' => $this->rows_scanned,
                'observations_parsed' => $this->observations_parsed,
                'observations_valid' => $this->observations_valid,
                'observations_rejected' => $this->observations_rejected,
                'warnings_count' => $this->warnings_count,
                'errors_count' => $this->errors_count,
            ],
            'progress' => $this->when($hasProgress, [
                'current_sheet' => $metadata['current_sheet'] ?? null,
                'current_row' => $metadata['current_row'] ?? null,
                'percent' => $metadata['progress_percent'] ?? null,
            ]),
            'failure' => $this->when($this->status === StatisticalImportStatus::Failed, [
                'code' => $this->failure_code,
                'message' => $this->failure_message,
            ]),
            'publication' => [
                'is_current' => $this->relationLoaded('activePointer') && $this->activePointer !== null,
                'supersedes_public_id' => $this->whenLoaded('supersedes', fn () => $this->supersedes?->public_id),
            ],
            'actions' => [
                'can_publish' => $this->status === StatisticalImportStatus::ReadyForPublish,
                'can_retry' => $this->status === StatisticalImportStatus::Failed,
                'can_view_observations' => $this->observations_valid > 0,
            ],
        ];
    }
}
