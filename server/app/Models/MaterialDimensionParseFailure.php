<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaterialDimensionParseFailure extends Model
{
    use HasFactory;

    protected $fillable = [
        'fingerprint',
        'raw_text',
        'normalized_text',
        'material_type',
        'source',
        'parse_error_reason',
        'occurrences',
        'first_seen_at',
        'last_seen_at',
        'resolved_length_mm',
        'resolved_width_mm',
        'resolved_thickness_mm',
        'resolution_note',
        'resolved_by_user_id',
        'resolved_at',
        'last_result',
    ];

    protected $casts = [
        'occurrences' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
        'last_result' => 'array',
    ];

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
