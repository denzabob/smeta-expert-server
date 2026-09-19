<?php

declare(strict_types=1);

namespace App\Http\Resources\Expert;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageMaterialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $available = $this->material !== null;
        $mimeType = $this->mime_type_snapshot;
        $kind = match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            in_array($mimeType, ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'], true) => 'spreadsheet',
            in_array($mimeType, ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/plain', 'text/markdown'], true) => 'document',
            default => 'other',
        };

        return [
            'material_public_id' => $this->material_public_id_snapshot,
            'original_name' => $this->original_name_snapshot,
            'mime_type' => $mimeType,
            'size' => $this->size_snapshot,
            'kind' => $kind,
            'available' => $available,
        ];
    }
}
