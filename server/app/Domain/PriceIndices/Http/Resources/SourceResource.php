<?php

namespace App\Domain\PriceIndices\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SourceResource extends JsonResource
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
            'code' => $this->code,
            'name' => $this->name,
            'source_page_url' => $this->source_page_url,
            'download_url_template' => $this->download_url_template,
            'filename_template' => $this->filename_template,
            'http_method' => $this->http_method,
            'is_enabled' => $this->is_enabled,
            'automatic_check_enabled' => $this->automatic_check_enabled,
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'last_success_at' => $this->last_success_at?->toIso8601String(),
            'next_check_at' => $this->next_check_at?->toIso8601String(),
            'consecutive_failures' => $this->consecutive_failures,
            'last_http_status' => $this->last_http_status,
            'last_error_code' => $this->last_error_code,
            'last_error_message' => $this->last_error_message,
            'settings_json' => $this->settings_json,
            'source_files_count' => $this->whenCounted('sourceFiles'),
            'checks_count' => $this->whenCounted('checks'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
