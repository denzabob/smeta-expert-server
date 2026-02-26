<?php
// app/Models/ProjectPosition.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectPosition extends Model
{
    use HasFactory;

    public const KIND_PANEL = 'panel';
    public const KIND_FACADE = 'facade';

    public const FINISH_TYPES = [
        'pvc_film', 'plastic', 'enamel', 'veneer',
        'solid_wood', 'aluminum_frame', 'other',
    ];

    public const PRICE_METHOD_SINGLE = 'single';
    public const PRICE_METHOD_MEAN = 'mean';
    public const PRICE_METHOD_MEDIAN = 'median';
    public const PRICE_METHOD_TRIMMED_MEAN = 'trimmed_mean';

    public const PRICE_METHODS = [
        self::PRICE_METHOD_SINGLE,
        self::PRICE_METHOD_MEAN,
        self::PRICE_METHOD_MEDIAN,
        self::PRICE_METHOD_TRIMMED_MEAN,
    ];

    protected $fillable = [
        'project_id',
        'kind',
        'detail_type_id',
        'material_id',
        'facade_material_id',
        'material_price_id',
        'edge_material_id',
        'edge_scheme',
        'quantity',
        'width',
        'length',
        'height',
        'custom_name',
        'custom_operations',
        'decor_label',
        'thickness_mm',
        'base_material_label',
        'finish_type',
        'finish_name',
        'price_per_m2',
        'area_m2',
        'total_price',
        'price_method',
        'price_sources_count',
        'price_min',
        'price_max',
    ];

    protected $casts = [
        'custom_fittings' => 'array',
        'custom_operations' => 'array',
        'price_per_m2' => 'decimal:4',
        'area_m2' => 'decimal:6',
        'total_price' => 'decimal:4',
        'thickness_mm' => 'integer',
        'price_sources_count' => 'integer',
        'price_min' => 'decimal:2',
        'price_max' => 'decimal:2',
    ];

    protected $appends = ['calculated_area_m2', 'calculated_total'];

    // === Computed attributes ===

    /**
     * Area = (width_mm / 1000) * (length_mm / 1000) * quantity
     */
    public function getCalculatedAreaM2Attribute(): float
    {
        return (($this->width ?? 0) / 1000) * (($this->length ?? 0) / 1000) * ($this->quantity ?? 0);
    }

    /**
     * Total = area_m2 * price_per_m2 (for m² items)
     * Called on save to persist area_m2 and total_price
     */
    public function getCalculatedTotalAttribute(): float
    {
        if ($this->kind === self::KIND_FACADE && $this->price_per_m2) {
            return $this->calculated_area_m2 * (float) $this->price_per_m2;
        }
        return 0;
    }

    /**
     * Recalculate area and total price, typically called before save
     */
    public function recalculate(): self
    {
        $this->area_m2 = $this->calculated_area_m2;

        if ($this->kind === self::KIND_FACADE && $this->price_per_m2) {
            $this->total_price = $this->area_m2 * (float) $this->price_per_m2;
        } else {
            $this->total_price = null;
        }

        return $this;
    }

    /**
     * Fill facade attributes from a Material(type=facade) and its price
     */
    public function fillFromFacadeMaterial(Material $material, ?MaterialPrice $price = null): self
    {
        $this->kind = self::KIND_FACADE;
        $this->facade_material_id = $material->id;
        $this->base_material_label = $material->getBaseMaterial() ? mb_strtoupper($material->getBaseMaterial()) : null;
        $this->thickness_mm = $material->thickness_mm;
        $this->finish_type = $material->getFinishType();
        $this->finish_name = $material->getFinishName();

        // Build decor_label
        $finishLabel = match ($this->finish_type) {
            'pvc_film' => 'ПВХ',
            'plastic' => 'Пластик',
            'enamel' => 'Эмаль',
            'veneer' => 'Шпон',
            'solid_wood' => 'Массив',
            'aluminum_frame' => 'Алюм. рамка',
            default => $this->finish_type ?? '',
        };
        $this->decor_label = trim("{$finishLabel} {$this->finish_name}");

        // Clear panel-specific fields
        $this->edge_material_id = null;
        $this->edge_scheme = 'none';

        if ($price) {
            $this->material_price_id = $price->id;
            $this->price_per_m2 = $price->price_per_internal_unit;
        }

        return $this->recalculate();
    }

    /**
     * Is facade position?
     */
    public function isFacade(): bool
    {
        return $this->kind === self::KIND_FACADE;
    }

    /**
     * Is panel position?
     */
    public function isPanel(): bool
    {
        return $this->kind === self::KIND_PANEL;
    }

    // === Relations ===

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function detailType()
    {
        return $this->belongsTo(DetailType::class);
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    public function edgeMaterial()
    {
        return $this->belongsTo(Material::class, 'edge_material_id');
    }

    public function facadeMaterial()
    {
        return $this->belongsTo(Material::class, 'facade_material_id');
    }

    public function materialPrice()
    {
        return $this->belongsTo(MaterialPrice::class, 'material_price_id');
    }

    public function priceQuotes()
    {
        return $this->hasMany(ProjectPositionPriceQuote::class, 'project_position_id');
    }

    /**
     * Is this position using aggregated pricing (mean/median/trimmed_mean)?
     */
    public function isAggregated(): bool
    {
        return $this->price_method && $this->price_method !== self::PRICE_METHOD_SINGLE;
    }

    /**
     * @deprecated Use materialPrice() instead
     */
    public function supplierPriceItem()
    {
        return $this->materialPrice();
    }

    // === Boot ===

    protected static function boot()
    {
        parent::boot();

        static::saving(function (ProjectPosition $position) {
            // Auto-recalculate area and total on save
            $position->recalculate();

            // Enforce facade constraints
            if ($position->isFacade()) {
                $position->edge_material_id = null;
                $position->edge_scheme = 'none';
            }
        });
    }
}
