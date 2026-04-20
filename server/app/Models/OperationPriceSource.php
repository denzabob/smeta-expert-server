<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class OperationPriceSource extends Model
{
    public const TYPE_MANUAL = 'manual';
    public const TYPE_IMPORT = 'import';
    public const TYPE_EXTERNAL = 'external';

    public $timestamps = false;

    protected $fillable = [
        'operation_id',
        'type',
        'value',
        'unit',
        'source_name',
        'document_ref',
        'is_active',
        'created_at',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function activate(): self
    {
        return DB::transaction(function () {
            static::query()
                ->where('operation_id', $this->operation_id)
                ->whereKeyNot($this->id)
                ->update(['is_active' => false]);

            $this->forceFill(['is_active' => true])->save();

            return $this->fresh();
        });
    }
}
