<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $region_id
 * @property string|null $default_expert_name
 * @property string|null $default_number
 * @property float $waste_coefficient
 * @property float $repair_coefficient
 * @property float|null $waste_plate_coefficient
 * @property float|null $waste_edge_coefficient
 * @property float|null $waste_operations_coefficient
 * @property bool $apply_waste_to_plate
 * @property bool $apply_waste_to_edge
 * @property bool $apply_waste_to_operations
 * @property bool $use_area_calc_mode
 * @property int|null $default_plate_material_id
 * @property int|null $default_edge_material_id
 * @property array|null $text_blocks
 * @property array|null $waste_plate_description
 * @property array|null $waste_edge_description
 * @property array|null $waste_operations_description
 * @property bool $show_waste_plate_description
 * @property bool $show_waste_edge_description
 * @property bool $show_waste_operations_description
 * @property string|null $created_at
 * @property string|null $updated_at
 */
class UserSettings extends Model
{
    protected $table = 'user_settings';

    protected $fillable = [
        'user_id',
        'region_id',
        'default_expert_name',
        'default_number',
        'waste_coefficient',
        'repair_coefficient',
        'waste_plate_coefficient',
        'waste_edge_coefficient',
        'waste_operations_coefficient',
        'apply_waste_to_plate',
        'apply_waste_to_edge',
        'apply_waste_to_operations',
        'use_area_calc_mode',
        'default_plate_material_id',
        'default_edge_material_id',
        'text_blocks',
        'waste_plate_description',
        'waste_edge_description',
        'waste_operations_description',
        'show_waste_plate_description',
        'show_waste_edge_description',
        'show_waste_operations_description',
    ];

    protected $casts = [
        'waste_coefficient' => 'float',
        'repair_coefficient' => 'float',
        'waste_plate_coefficient' => 'float',
        'waste_edge_coefficient' => 'float',
        'waste_operations_coefficient' => 'float',
        'apply_waste_to_plate' => 'boolean',
        'apply_waste_to_edge' => 'boolean',
        'apply_waste_to_operations' => 'boolean',
        'use_area_calc_mode' => 'boolean',
        'show_waste_plate_description' => 'boolean',
        'show_waste_edge_description' => 'boolean',
        'show_waste_operations_description' => 'boolean',
        'text_blocks' => 'array',
        'waste_plate_description' => 'array',
        'waste_edge_description' => 'array',
        'waste_operations_description' => 'array',
    ];

    /**
     * Получить пользователя, которому принадлежат эти настройки
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Получить регион по умолчанию
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * Получить материал плиты по умолчанию
     */
    public function defaultPlateMaterial(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'default_plate_material_id');
    }

    /**
     * Получить материал кромки по умолчанию
     */
    public function defaultEdgeMaterial(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'default_edge_material_id');
    }

    /**
     * Создать настройки пользователя с дефолтными значениями
     */
    public static function createForUser(User $user): self
    {
        return self::create([
            'user_id' => $user->id,
            'region_id' => null,
            'waste_coefficient' => 1.0,
            'repair_coefficient' => 1.0,
            'waste_plate_coefficient' => null,
            'waste_edge_coefficient' => null,
            'waste_operations_coefficient' => null,
            'apply_waste_to_plate' => true,
            'apply_waste_to_edge' => true,
            'apply_waste_to_operations' => false,
            'use_area_calc_mode' => false,
            'default_plate_material_id' => null,
            'default_edge_material_id' => null,
            'text_blocks' => null,
            'waste_plate_description' => null,
            'waste_edge_description' => null,
            'waste_operations_description' => null,
            'show_waste_plate_description' => false,
            'show_waste_edge_description' => false,
            'show_waste_operations_description' => false,
        ]);
    }
}
