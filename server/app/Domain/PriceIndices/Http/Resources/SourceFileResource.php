<?php

namespace App\Domain\PriceIndices\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SourceFileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'dataset' => $this->whenLoaded('dataset', fn () => [
                'public_id' => $this->dataset->public_id,
                'code' => $this->dataset->code,
                'name' => $this->dataset->name,
            ]),
            'source' => $this->whenLoaded('source', fn () => $this->source === null ? null : [
                'public_id' => $this->source->public_id,
                'code' => $this->source->code,
                'name' => $this->source->name,
            ]),
            'acquisition_method' => $this->acquisition_method->value,
            'reporting_year' => $this->reporting_year,
            'reporting_month' => $this->reporting_month,
            'original_filename' => $this->original_filename,
            'source_url' => $this->source_url,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'sha256' => $this->sha256,
            'detected_at' => $this->detected_at?->toIso8601String(),
            'status' => $this->status->value,
            'validation_status' => $this->validation_status->value,
            'validation_summary' => $this->validation_summary_json,
            'rejection_reason' => $this->when($this->rejection_reason !== null, $this->rejection_reason),
            'reviewed_by' => $this->whenLoaded('reviewedBy', fn () => $this->userSummary($this->reviewedBy)),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'activated_by' => $this->whenLoaded('activatedBy', fn () => $this->userSummary($this->activatedBy)),
            'activated_at' => $this->activated_at?->toIso8601String(),
            'active' => $this->relationLoaded('activePointer') && $this->activePointer !== null,
            'supersedes_public_id' => $this->whenLoaded('supersedes', fn () => $this->supersedes?->public_id),
            'metadata_json' => $this->metadata_json,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{name: string|null}|null
     */
    private function userSummary(?User $user): ?array
    {
        return $user === null ? null : ['name' => $user->name];
    }
}
