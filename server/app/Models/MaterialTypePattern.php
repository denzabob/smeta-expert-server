<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaterialTypePattern extends Model
{
    use HasFactory;

    public const RULE_TYPE_REGEX = 'regex';

    public const TARGET_TITLE = 'title';
    public const TARGET_URL = 'url';
    public const TARGET_TITLE_OR_URL = 'title_or_url';

    public const TARGET_FIELDS = [
        self::TARGET_TITLE,
        self::TARGET_URL,
        self::TARGET_TITLE_OR_URL,
    ];

    protected $fillable = [
        'name',
        'description',
        'is_active',
        'priority',
        'material_type',
        'source',
        'rule_type',
        'target_field',
        'pattern',
        'flags',
        'use_normalized_text',
        'example_input',
        'expected_material_type',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'priority' => 'integer',
        'use_normalized_text' => 'boolean',
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
