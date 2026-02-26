<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Material extends Model
{
    use HasFactory;

    // Material types
    public const TYPE_PLATE = 'plate';
    public const TYPE_EDGE = 'edge';
    public const TYPE_FACADE = 'facade';
    public const TYPE_HARDWARE = 'hardware';

    // Visibility levels
    public const VISIBILITY_PRIVATE = 'private';
    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_CURATED = 'curated';

    // Trust levels
    public const TRUST_UNVERIFIED = 'unverified';
    public const TRUST_PARTIAL = 'partial';
    public const TRUST_VERIFIED = 'verified';

    // Data origins
    public const ORIGIN_MANUAL = 'manual';
    public const ORIGIN_URL_PARSE = 'url_parse';
    public const ORIGIN_PRICE_LIST = 'price_list';
    public const ORIGIN_CHROME_EXT = 'chrome_ext';

    // Parse statuses
    public const PARSE_OK = 'ok';
    public const PARSE_FAILED = 'failed';
    public const PARSE_BLOCKED = 'blocked';
    public const PARSE_UNSUPPORTED = 'unsupported';

    // Facade finish types
    public const FINISH_PVC_FILM = 'pvc_film';
    public const FINISH_PLASTIC = 'plastic';
    public const FINISH_ENAMEL = 'enamel';
    public const FINISH_VENEER = 'veneer';
    public const FINISH_SOLID_WOOD = 'solid_wood';
    public const FINISH_ALUMINUM_FRAME = 'aluminum_frame';
    public const FINISH_OTHER = 'other';

    public const FINISH_TYPES = [
        self::FINISH_PVC_FILM,
        self::FINISH_PLASTIC,
        self::FINISH_ENAMEL,
        self::FINISH_VENEER,
        self::FINISH_SOLID_WOOD,
        self::FINISH_ALUMINUM_FRAME,
        self::FINISH_OTHER,
    ];

    // Display labels for finish types
    public const FINISH_LABELS = [
        self::FINISH_PVC_FILM => 'ПВХ плёнка',
        self::FINISH_PLASTIC => 'Пластик',
        self::FINISH_ENAMEL => 'Эмаль',
        self::FINISH_VENEER => 'Шпон',
        self::FINISH_SOLID_WOOD => 'Массив',
        self::FINISH_ALUMINUM_FRAME => 'Алюм. рамка',
        self::FINISH_OTHER => 'Другое',
    ];

    // Facade base materials
    public const BASE_MATERIALS = ['mdf', 'dsp', 'mdf_aglo', 'fanera', 'massiv'];

    public const BASE_MATERIAL_LABELS = [
        'mdf' => 'МДФ',
        'dsp' => 'ДСП',
        'mdf_aglo' => 'МДФ-Агло',
        'fanera' => 'Фанера',
        'massiv' => 'Массив',
    ];

    // Price groups (ценовая группа плёнки 1..5)
    public const PRICE_GROUPS = ['1', '2', '3', '4', '5'];

    // Finish variants (вид плёнки/покрытия)
    public const VARIANT_MATTE = 'matte';
    public const VARIANT_GLOSS = 'gloss';
    public const VARIANT_METALLIC = 'metallic';
    public const VARIANT_SOFT_TOUCH = 'soft_touch';
    public const VARIANT_TEXTURED = 'textured';

    public const FINISH_VARIANTS = [
        self::VARIANT_MATTE,
        self::VARIANT_GLOSS,
        self::VARIANT_METALLIC,
        self::VARIANT_SOFT_TOUCH,
        self::VARIANT_TEXTURED,
    ];

    public const FINISH_VARIANT_LABELS = [
        self::VARIANT_MATTE => 'Матовая',
        self::VARIANT_GLOSS => 'Глянец',
        self::VARIANT_METALLIC => 'Металлик',
        self::VARIANT_SOFT_TOUCH => 'Софт-тач',
        self::VARIANT_TEXTURED => 'Структурная',
    ];

    // Facade classes (MVP 10 fixed)
    public const FACADE_CLASS_STANDARD = 'STANDARD';
    public const FACADE_CLASS_PREMIUM = 'PREMIUM';
    public const FACADE_CLASS_GEOMETRY = 'GEOMETRY';
    public const FACADE_CLASS_RADIUS = 'RADIUS';
    public const FACADE_CLASS_VITRINA = 'VITRINA';
    public const FACADE_CLASS_RESHETKA = 'RESHETKA';
    public const FACADE_CLASS_AKRIL = 'AKRIL';
    public const FACADE_CLASS_ALUMINIUM = 'ALUMINIUM';
    public const FACADE_CLASS_MASSIV = 'MASSIV';
    public const FACADE_CLASS_ECONOMY = 'ECONOMY';

    public const FACADE_CLASSES = [
        self::FACADE_CLASS_STANDARD,
        self::FACADE_CLASS_PREMIUM,
        self::FACADE_CLASS_GEOMETRY,
        self::FACADE_CLASS_RADIUS,
        self::FACADE_CLASS_VITRINA,
        self::FACADE_CLASS_RESHETKA,
        self::FACADE_CLASS_AKRIL,
        self::FACADE_CLASS_ALUMINIUM,
        self::FACADE_CLASS_MASSIV,
        self::FACADE_CLASS_ECONOMY,
    ];

    public const FACADE_CLASS_LABELS = [
        self::FACADE_CLASS_STANDARD => 'Стандарт',
        self::FACADE_CLASS_PREMIUM => 'Премиум',
        self::FACADE_CLASS_GEOMETRY => 'Геометрия',
        self::FACADE_CLASS_RADIUS => 'Радиус',
        self::FACADE_CLASS_VITRINA => 'Витрина',
        self::FACADE_CLASS_RESHETKA => 'Решётка',
        self::FACADE_CLASS_AKRIL => 'Акрил',
        self::FACADE_CLASS_ALUMINIUM => 'Алюминий',
        self::FACADE_CLASS_MASSIV => 'Массив',
        self::FACADE_CLASS_ECONOMY => 'Эконом',
    ];

    protected $fillable = [
        'user_id',
        'origin',
        'name',
        'search_name',
        'article',
        'type',
        'material_tag',
        'thickness',
        'waste_factor',
        'unit',
        'price_per_unit',
        'source_url',
        'last_price_screenshot_path',
        'availability_status',
        'price_checked_at',
        'is_active',
        'version',
        'length_mm',
        'width_mm',
        'thickness_mm',
        'operation_ids',
        'metadata',
        // Facade structured columns
        'facade_class',
        'facade_base_type',
        'facade_thickness_mm',
        'facade_covering',
        'facade_cover_type',
        'facade_collection',
        'facade_price_group_label',
        'facade_decor_label',
        'facade_article_optional',
        // Catalog fields
        'visibility',
        'curator_user_id',
        'published_at',
        'curated_at',
        'trust_score',
        'trust_level',
        'data_origin',
        'last_parsed_at',
        'last_parse_status',
        'last_parse_error',
        'region_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'price_per_unit' => 'decimal:2',
        'version' => 'integer',
        'thickness' => 'decimal:2',
        'waste_factor' => 'decimal:2',
        'operation_ids' => 'array',
        'metadata' => 'array',
        'price_checked_at' => 'datetime',
        'facade_thickness_mm' => 'integer',
        'published_at' => 'datetime',
        'curated_at' => 'datetime',
        'last_parsed_at' => 'datetime',
        'trust_score' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($material) {
            // Generate search_name
            if (!isset($material->search_name) && isset($material->name)) {
                $material->search_name = self::normalizeSearchName($material->name);
            }
        });

        static::updating(function ($material) {
            // Update search_name if name changed
            if ($material->isDirty('name')) {
                $material->search_name = self::normalizeSearchName($material->name);
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

    public function priceHistories()
    {
        return $this->hasMany(MaterialPriceHistory::class);
    }

    /**
     * Get prices from all versions.
     */
    public function prices()
    {
        return $this->hasMany(MaterialPrice::class);
    }

    /**
     * Get aliases.
     */
    public function aliases()
    {
        return $this->hasMany(SupplierProductAlias::class, 'internal_item_id')
            ->where('internal_item_type', 'material');
    }

    // Связь с пользователем (если нужно)
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Curator relationship.
     */
    public function curator()
    {
        return $this->belongsTo(User::class, 'curator_user_id');
    }

    /**
     * Region relationship.
     */
    public function region()
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * Users who have this material in their library.
     */
    public function libraryUsers()
    {
        return $this->hasMany(UserMaterialLibrary::class);
    }

    // --- Scopes for catalog ---

    /**
     * Scope: only public materials.
     */
    public function scopePublicVisible($query)
    {
        return $query->whereIn('visibility', [self::VISIBILITY_PUBLIC, self::VISIBILITY_CURATED]);
    }

    /**
     * Scope: materials visible to a specific user.
     */
    public function scopeVisibleTo($query, $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->where('user_id', $userId)
              ->orWhereIn('visibility', [self::VISIBILITY_PUBLIC, self::VISIBILITY_CURATED]);
        })->where('is_active', true);
    }

    /**
     * Scope: hardware type.
     */
    public function scopeHardware($query)
    {
        return $query->where('type', self::TYPE_HARDWARE);
    }

    // === Facade helpers ===

    /**
     * Check if this material is a facade
     */
    public function isFacade(): bool
    {
        return $this->type === self::TYPE_FACADE;
    }

    /**
     * Get facade finish type (enum code) from metadata.
     * Reads: finish.type → finish_type (flat legacy)
     */
    public function getFinishType(): ?string
    {
        return $this->metadata['finish']['type']
            ?? $this->metadata['finish_type']
            ?? null;
    }

    /**
     * Get facade finish name (human-readable label) from metadata.
     * Reads: finish.name → finish_type label fallback
     */
    public function getFinishName(): ?string
    {
        return $this->metadata['finish']['name']
            ?? null;
    }

    /**
     * Get facade finish variant from metadata.
     * Reads: finish.variant (матовая/глянец/металлик)
     */
    public function getFinishVariant(): ?string
    {
        return $this->metadata['finish']['variant'] ?? null;
    }

    /**
     * Get facade base material from metadata.
     * Reads: base.material → base_material (flat legacy)
     */
    public function getBaseMaterial(): ?string
    {
        return $this->metadata['base']['material']
            ?? $this->metadata['base_material']
            ?? null;
    }

    /**
     * Get facade collection from metadata.
     * Reads: collection (top-level) → finish.collection (legacy)
     */
    public function getFacadeCollection(): ?string
    {
        return $this->metadata['collection']
            ?? $this->metadata['finish']['collection']
            ?? null;
    }

    /**
     * Get facade decor from metadata.
     * Reads: decor (top-level) → finish.name fallback → decor (flat legacy)
     */
    public function getDecor(): ?string
    {
        return $this->metadata['decor']
            ?? $this->metadata['finish']['name']
            ?? null;
    }

    /**
     * Get facade price group (1..5) from metadata.
     */
    public function getPriceGroup(): ?string
    {
        return $this->metadata['price_group'] ?? null;
    }

    /**
     * Get facade film article from metadata.
     */
    public function getFilmArticle(): ?string
    {
        return $this->metadata['film_article']
            ?? $this->metadata['finish']['code']
            ?? null;
    }

    /**
     * Extract all facade spec fields from metadata.
     * Handles legacy formats (flat, nested) and produces a normalized spec.
     */
    public function getFacadeSpec(): array
    {
        return [
            'base_material' => $this->getBaseMaterial() ?? 'mdf',
            'thickness_mm' => (int) ($this->metadata['thickness_mm']
                ?? $this->thickness_mm
                ?? 0),
            'finish_type' => $this->getFinishType() ?? '',
            'finish_name' => $this->getFinishName() ?? (self::FINISH_LABELS[$this->getFinishType()] ?? ''),
            'finish_variant' => $this->getFinishVariant() ?? '',
            'collection' => $this->getFacadeCollection() ?? '',
            'decor' => $this->getDecor() ?? '',
            'price_group' => $this->getPriceGroup() ?? '',
            'film_article' => $this->getFilmArticle() ?? '',
        ];
    }

    /**
     * Build a deduplication key for facade materials.
     * Used to find existing facade when importing prices.
     */
    public static function buildFacadeKey(string $baseMaterial, int $thicknessMm, string $finishType, string $finishCode): string
    {
        $normalized = mb_strtolower(trim("{$baseMaterial}|{$thicknessMm}|{$finishType}|{$finishCode}"));
        return md5($normalized);
    }

    /**
     * Generate a human-readable name for a facade
     */
    public static function buildFacadeName(string $baseMaterial, int $thicknessMm, string $finishName, string $finishType): string
    {
        $finishLabel = match($finishType) {
            self::FINISH_PVC_FILM => 'ПВХ',
            self::FINISH_PLASTIC => 'Пластик',
            self::FINISH_ENAMEL => 'Эмаль',
            self::FINISH_VENEER => 'Шпон',
            self::FINISH_SOLID_WOOD => 'Массив',
            self::FINISH_ALUMINUM_FRAME => 'Алюм. рамка',
            default => $finishType,
        };

        return "Фасад " . mb_strtoupper($baseMaterial) . " {$thicknessMm}мм, {$finishLabel} {$finishName}";
    }

    /**
     * Find existing facade by dedup key (v1 — backward compat)
     */
    public static function findFacadeByKey(string $baseMaterial, int $thicknessMm, string $finishType, string $finishCode): ?self
    {
        $key = self::buildFacadeKey($baseMaterial, $thicknessMm, $finishType, $finishCode);
        $article = 'FACADE:' . $key;

        return self::where('type', self::TYPE_FACADE)
            ->where('article', $article)
            ->first();
    }

    // === Facade Spec ===

    /**
     * Build a deduplication key for facade specs.
     * spec_key = normalize(base_material) + thickness_mm + normalize(finish_type)
     *          + normalize(collection) + normalize(decor) + price_group
     */
    public static function buildFacadeSpecKey(array $spec): string
    {
        $parts = implode('|', [
            mb_strtolower(trim($spec['base_material'] ?? 'mdf')),
            (int) ($spec['thickness_mm'] ?? 16),
            mb_strtolower(trim($spec['finish_type'] ?? '')),
            mb_strtolower(trim($spec['collection'] ?? '')),
            mb_strtolower(trim($spec['decor'] ?? '')),
            trim($spec['price_group'] ?? ''),
        ]);

        return md5($parts);
    }

    /**
     * Build facade metadata in the canonical nested format.
     *
     * Structure:
     *   base.material, thickness_mm, finish.type, finish.name, finish.variant,
     *   collection, decor, price_group, film_article
     */
    public static function buildFacadeMetadata(array $spec): array
    {
        $finishType = $spec['finish_type'] ?? '';

        $meta = [
            'base' => ['material' => $spec['base_material'] ?? 'mdf'],
            'thickness_mm' => (int) ($spec['thickness_mm'] ?? 16),
            'finish' => [
                'type' => $finishType,
                'name' => $spec['finish_name'] ?? (self::FINISH_LABELS[$finishType] ?? $finishType),
            ],
            'collection' => $spec['collection'] ?? '',
            'decor' => $spec['decor'] ?? '',
            'price_group' => $spec['price_group'] ?? '',
        ];

        if (!empty($spec['finish_variant'])) {
            $meta['finish']['variant'] = $spec['finish_variant'];
        }
        if (!empty($spec['film_article'])) {
            $meta['film_article'] = $spec['film_article'];
        }

        return $meta;
    }

    /**
     * Generate a human-readable name for a facade spec.
     * Format: "{collection} / Группа {price_group} / {decor} / {thickness_mm}мм"
     * Falls back to parts-based format when some fields are empty.
     */
    public static function buildFacadeSpecName(array $spec): string
    {
        $segments = [];

        if (!empty($spec['collection'])) {
            $segments[] = $spec['collection'];
        }

        if (!empty($spec['price_group'])) {
            $segments[] = 'Группа ' . $spec['price_group'];
        }

        if (!empty($spec['decor'])) {
            $segments[] = $spec['decor'];
        }

        $thicknessMm = $spec['thickness_mm'] ?? 16;
        $segments[] = "{$thicknessMm}мм";

        // If we have meaningful segments, use the ticket format
        if (count($segments) > 1) {
            return implode(' / ', $segments);
        }

        // Fallback: include base material and finish info
        $parts = [];
        $baseMaterial = $spec['base_material'] ?? 'mdf';
        $parts[] = self::BASE_MATERIAL_LABELS[$baseMaterial] ?? mb_strtoupper($baseMaterial);
        $parts[] = "{$thicknessMm} мм";

        $finishType = $spec['finish_type'] ?? '';
        if ($finishType) {
            $parts[] = self::FINISH_LABELS[$finishType] ?? $finishType;
        }
        if (!empty($spec['decor'])) {
            $parts[] = $spec['decor'];
        }

        return implode(', ', $parts);
    }

    /**
     * Find or create a facade material by spec.
     * Tries current spec key first, falls back to v1 key, creates if not found.
     *
     * @param array $spec Keys: base_material, thickness_mm, finish_type, finish_name,
     *                          finish_variant, collection, decor, price_group, film_article
     * @return array{material: self, created: bool}
     */
    public static function findOrCreateFacadeSpec(array $spec): array
    {
        $baseMaterial = trim($spec['base_material'] ?? 'mdf');
        $thicknessMm = (int) ($spec['thickness_mm'] ?? 16);
        $finishType = trim($spec['finish_type'] ?? '');
        $collection = trim($spec['collection'] ?? '');
        $decor = trim($spec['decor'] ?? '');
        $priceGroup = trim($spec['price_group'] ?? '');

        // 1. Try spec key
        $specKey = self::buildFacadeSpecKey($spec);
        $existing = self::where('type', self::TYPE_FACADE)
            ->where('article', 'FACADE:' . $specKey)
            ->first();

        if ($existing) {
            return ['material' => $existing, 'created' => false];
        }

        // 2. Try v1 key (backward compat: base|thickness|finishType|finishCode)
        $finishCode = $decor ?: ($spec['film_article'] ?? '');
        $v1Key = self::buildFacadeKey($baseMaterial, $thicknessMm, $finishType, $finishCode);
        $existingV1 = self::where('type', self::TYPE_FACADE)
            ->where('article', 'FACADE:' . $v1Key)
            ->first();

        if ($existingV1) {
            // Upgrade to current format
            $existingV1->article = 'FACADE:' . $specKey;
            $existingV1->metadata = self::buildFacadeMetadata($spec);
            $existingV1->name = self::buildFacadeSpecName($spec);
            $existingV1->save();
            return ['material' => $existingV1, 'created' => false];
        }

        // 3. Create new
        $material = self::create([
            'type' => self::TYPE_FACADE,
            'unit' => 'м²',
            'name' => self::buildFacadeSpecName($spec),
            'article' => 'FACADE:' . $specKey,
            'material_tag' => $baseMaterial,
            'thickness_mm' => $thicknessMm,
            'thickness' => $thicknessMm,
            'origin' => 'user',
            'is_active' => true,
            'metadata' => self::buildFacadeMetadata($spec),
        ]);

        return ['material' => $material, 'created' => true];
    }

    /**
     * Scope: only facades
     */
    public function scopeFacades($query)
    {
        return $query->where('type', self::TYPE_FACADE);
    }

    /**
     * Scope: only plates
     */
    public function scopePlates($query)
    {
        return $query->where('type', self::TYPE_PLATE);
    }

    /**
     * Scope: only edges
     */
    public function scopeEdges($query)
    {
        return $query->where('type', self::TYPE_EDGE);
    }
}
