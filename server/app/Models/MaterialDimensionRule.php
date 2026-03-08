<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaterialDimensionRule extends Model
{
    use HasFactory;

    public const RULE_TYPE_REGEX = 'regex';

    protected $fillable = [
        'name',
        'description',
        'is_active',
        'priority',
        'material_type',
        'source',
        'rule_type',
        'config',
        'example_input',
        'expected_result',
        'confidence',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'priority' => 'integer',
        'config' => 'array',
        'expected_result' => 'array',
        'confidence' => 'float',
    ];

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
