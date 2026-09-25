<?php

declare(strict_types=1);

namespace App\Models\Expert;

use Illuminate\Database\Eloquent\Model;

final class ExpertMaterialIdentity extends Model
{
    protected $fillable = [
        'expert_project_material_id', 'schema_version', 'state', 'source_sha256',
        'metadata_hash', 'content_fingerprint', 'descriptor', 'routing_text',
        'content_source', 'confidence', 'last_error_code', 'built_at',
    ];

    protected $hidden = ['id', 'expert_project_material_id'];

    protected $casts = ['descriptor' => 'array', 'built_at' => 'datetime', 'confidence' => 'float'];

    public function material()
    {
        return $this->belongsTo(ExpertProjectMaterial::class, 'expert_project_material_id');
    }

    /** @return array<string, mixed> */
    public function toRoutingDescriptor(ExpertProjectMaterial $material): array
    {
        $descriptor = is_array($this->descriptor) ? $this->descriptor : [];

        return [
            'material_id' => (string) $material->public_id,
            'state' => (string) $this->state,
            'schema_version' => (string) $this->schema_version,
            'display_name' => (string) ($descriptor['display_name'] ?? $material->original_name),
            'aliases' => array_values(array_filter($descriptor['aliases'] ?? [], 'is_string')),
            'mime_type' => (string) $material->mime_type,
            'extension' => (string) $material->extension,
            'category' => (string) $material->category,
            'routing_text' => $this->state === 'content_enriched' ? $this->routing_text : null,
            'content_source' => (string) ($this->content_source ?? 'filename'),
            'content_available' => $this->state === 'content_enriched',
            'source_sha256' => $this->source_sha256,
            'metadata_hash' => (string) $this->metadata_hash,
            'content_fingerprint' => $this->state === 'content_enriched' ? $this->content_fingerprint : null,
            'confidence' => $this->confidence === null ? 0.0 : (float) $this->confidence,
            'built_at' => $this->built_at?->toIso8601String(),
        ];
    }
}
