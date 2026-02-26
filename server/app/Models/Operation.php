<?php
// app/Models/Operation.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Operation extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'search_name',
        'category',
        'exclusion_group',
        'min_thickness',
        'max_thickness',
        'unit',
        'description',
        'user_id',
        'origin',
    ];

    protected $casts = [
        'min_thickness' => 'decimal:2',
        'max_thickness' => 'decimal:2',
    ];

    // Автоматически устанавливать user_id, origin и search_name при создании/обновлении
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($operation) {
            if (!isset($operation->user_id)) {
                $operation->user_id = Auth::id();
            }
            if (!isset($operation->origin)) {
                $operation->origin = $operation->user_id ? 'user' : 'system';
            }
            // Generate search_name
            if (!isset($operation->search_name) && isset($operation->name)) {
                $operation->search_name = self::normalizeSearchName($operation->name);
            }
        });

        static::updating(function ($operation) {
            // Update search_name if name changed
            if ($operation->isDirty('name')) {
                $operation->search_name = self::normalizeSearchName($operation->name);
            }
        });
    }

    /**
     * Normalize name for search.
     */
    public static function normalizeSearchName(string $name): string
    {
        $name = mb_strtolower($name, 'UTF-8');
        $name = preg_replace('/["\',;:!?\.]+/', '', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        return trim($name);
    }

    /**
     * Get prices from all versions.
     */
    public function prices()
    {
        return $this->hasMany(OperationPrice::class);
    }

    /**
     * Get aliases.
     */
    public function aliases()
    {
        return $this->hasMany(SupplierProductAlias::class, 'internal_item_id')
            ->where('internal_item_type', 'operation');
    }
}
