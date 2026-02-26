<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Связь между operation_group и supplier_operation.
 */
class OperationGroupLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'operation_group_id',
        'supplier_operation_id',
    ];

    /**
     * Get the operation group.
     */
    public function operationGroup(): BelongsTo
    {
        return $this->belongsTo(OperationGroup::class);
    }

    /**
     * Get the supplier operation.
     */
    public function supplierOperation(): BelongsTo
    {
        return $this->belongsTo(SupplierOperation::class);
    }
}
