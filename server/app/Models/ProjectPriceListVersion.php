<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot: which price_list_versions are used in a project's calculations.
 * Serves as the minimal evidence layer — each version already stores
 * sha256, source_type, source_url, captured_at, file_path.
 */
class ProjectPriceListVersion extends Model
{
    protected $fillable = [
        'project_id',
        'price_list_version_id',
        'role',
        'linked_at',
    ];

    protected $casts = [
        'linked_at' => 'datetime',
    ];

    public const ROLE_MATERIAL = 'material_price';
    public const ROLE_OPERATION = 'operation_price';
    public const ROLE_FACADE = 'facade_price';

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function priceListVersion(): BelongsTo
    {
        return $this->belongsTo(PriceListVersion::class);
    }

    /**
     * Register a price_list_version usage for a project (idempotent).
     */
    public static function link(int $projectId, int $priceListVersionId, string $role = self::ROLE_MATERIAL): self
    {
        return static::firstOrCreate([
            'project_id' => $projectId,
            'price_list_version_id' => $priceListVersionId,
            'role' => $role,
        ], [
            'linked_at' => now(),
        ]);
    }
}
