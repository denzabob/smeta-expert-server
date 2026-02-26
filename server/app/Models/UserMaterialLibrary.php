<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserMaterialLibrary extends Model
{
    use HasFactory;

    protected $table = 'user_material_library';

    protected $fillable = [
        'user_id',
        'material_id',
        'pinned',
        'preferred_region_id',
        'preferred_price_source_url',
        'notes',
    ];

    protected $casts = [
        'pinned' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    public function preferredRegion()
    {
        return $this->belongsTo(Region::class, 'preferred_region_id');
    }

    // --- Scopes ---

    public function scopePinned($query)
    {
        return $query->where('pinned', true);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
