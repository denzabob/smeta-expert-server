<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single price quote used in an aggregated facade position price.
 * Stores a snapshot of the price at the time it was selected.
 */
class ProjectPositionPriceQuote extends Model
{
    protected $table = 'project_position_price_quotes';

    protected $fillable = [
        'project_position_id',
        'material_price_id',
        'price_list_version_id',
        'supplier_id',
        'price_per_m2_snapshot',
        'captured_at',
        'mismatch_flags',
    ];

    protected $casts = [
        'price_per_m2_snapshot' => 'decimal:2',
        'captured_at' => 'datetime',
        'mismatch_flags' => 'array',
    ];

    public function position(): BelongsTo
    {
        return $this->belongsTo(ProjectPosition::class, 'project_position_id');
    }

    public function materialPrice(): BelongsTo
    {
        return $this->belongsTo(MaterialPrice::class, 'material_price_id');
    }

    public function priceListVersion(): BelongsTo
    {
        return $this->belongsTo(PriceListVersion::class, 'price_list_version_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}
