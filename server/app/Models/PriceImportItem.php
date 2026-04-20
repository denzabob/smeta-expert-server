<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceImportItem extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_LINKED = 'linked';
    public const STATUS_IGNORED = 'ignored';

    protected $fillable = [
        'import_id',
        'operation_id',
        'name',
        'value',
        'unit',
        'parsed_operation_hint',
        'status',
    ];

    protected $casts = [
        'value' => 'decimal:2',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(PriceImport::class, 'import_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }
}
