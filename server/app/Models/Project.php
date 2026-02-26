<?php
// app/Models/Project.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'number',
        'expert_name',
        'address',
        'region_id',
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
        'show_waste_plate_description',
        'waste_edge_description',
        'show_waste_edge_description',
        'waste_operations_description',
        'show_waste_operations_description',
        'normohour_rate',
        'normohour_region',
        'normohour_date',
        'normohour_method',
        'normohour_justification',
    ];

    // Автоматически загружать связанные данные при сериализации
    protected $with = ['profileRates'];

    protected $casts = [
        'text_blocks' => 'json',
        'waste_plate_description' => 'json',
        'waste_edge_description' => 'json',
        'waste_operations_description' => 'json',
        'normohour_date' => 'date',
        'archived_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function positions()
    {
        return $this->hasMany(ProjectPosition::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    public function fittings()
    {
        return $this->hasMany(ProjectFitting::class);
    }

    public function manualOperations()
    {
        return $this->hasMany(ProjectManualOperation::class);
    }

    public function normohourSources()
    {
        return $this->hasMany(ProjectNormohourSource::class);
    }

    public function laborWorks()
    {
        return $this->hasMany(ProjectLaborWork::class)->orderBy('sort_order');
    }

    public function region()
    {
        return $this->belongsTo(Region::class);
    }

    public function profileRates()
    {
        return $this->hasMany(ProjectProfileRate::class);
    }

    public function revisions()
    {
        return $this->hasMany(ProjectRevision::class)->orderByDesc('number');
    }

    public function latestRevision()
    {
        return $this->hasOne(ProjectRevision::class)->latestOfMany('number');
    }

    public function priceListVersionLinks()
    {
        return $this->hasMany(ProjectPriceListVersion::class);
    }

    public function priceListVersions()
    {
        return $this->belongsToMany(PriceListVersion::class, 'project_price_list_versions')
            ->withPivot('role', 'linked_at')
            ->withTimestamps();
    }
}
