<?php

declare(strict_types=1);

namespace App\Models\Expert;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpertMessageMaterial extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'expert_project_material_id',
        'position',
        'material_public_id_snapshot',
        'original_name_snapshot',
        'mime_type_snapshot',
        'size_snapshot',
    ];

    protected $casts = ['position' => 'integer', 'size_snapshot' => 'integer', 'created_at' => 'datetime'];

    public function material(): BelongsTo
    {
        return $this->belongsTo(ExpertProjectMaterial::class, 'expert_project_material_id');
    }
}
