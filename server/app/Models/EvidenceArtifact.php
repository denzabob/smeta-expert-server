<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvidenceArtifact extends Model
{
    use HasFactory;

    public const MODE_AUTO = 'auto';
    public const MODE_MANUAL = 'manual';

    protected $fillable = [
        'uuid',
        'material_id',
        'revision_run_id',
        'revision_run_item_id',
        'mode',
        'source_url_raw',
        'source_url_normalized',
        'source_domain',
        'page_type',
        'block_type',
        'http_status',
        'parser_profile_id',
        'parser_version',
        'extracted_price',
        'currency',
        'extracted_name',
        'extracted_article',
        'screenshot_path',
        'screenshot_sha256',
        'html_sha256',
        'viewport_w',
        'viewport_h',
        'user_agent_hash',
        'confidence_score',
        'trust_score',
        'reason_code',
        'reason_details_json',
        'captured_at',
        'created_by',
    ];

    protected $casts = [
        'http_status' => 'integer',
        'extracted_price' => 'decimal:2',
        'viewport_w' => 'integer',
        'viewport_h' => 'integer',
        'confidence_score' => 'integer',
        'trust_score' => 'integer',
        'reason_details_json' => 'array',
        'captured_at' => 'datetime',
    ];

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function revisionRun(): BelongsTo
    {
        return $this->belongsTo(RevisionRun::class);
    }

    public function revisionRunItem(): BelongsTo
    {
        return $this->belongsTo(RevisionRunItem::class);
    }

    public function parserProfile(): BelongsTo
    {
        return $this->belongsTo(ParserSupplierCollectProfile::class, 'parser_profile_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function materialPriceHistories(): HasMany
    {
        return $this->hasMany(MaterialPriceHistory::class, 'evidence_artifact_id');
    }
}

