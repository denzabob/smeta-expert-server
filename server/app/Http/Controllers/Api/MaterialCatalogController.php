<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddPriceObservationRequest;
use App\Http\Requests\ParseByUrlRequest;
use App\Http\Requests\StoreCatalogMaterialRequest;
use App\Models\Material;
use App\Models\MaterialPriceHistory;
use App\Models\UserMaterialLibrary;
use App\Models\UserSettings;
use App\Services\DomainParseService;
use App\Services\MaterialDeduplicationService;
use App\Services\MaterialParseService;
use App\Services\TrustScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MaterialCatalogController extends Controller
{
    protected MaterialParseService $parseService;
    protected TrustScoreService $trustScoreService;
    protected MaterialDeduplicationService $dedupService;
    protected DomainParseService $domainParseService;

    public function __construct(
        MaterialParseService $parseService,
        TrustScoreService $trustScoreService,
        MaterialDeduplicationService $dedupService,
        DomainParseService $domainParseService
    ) {
        $this->parseService = $parseService;
        $this->trustScoreService = $trustScoreService;
        $this->dedupService = $dedupService;
        $this->domainParseService = $domainParseService;
    }

    // ========================================================================
    // CATALOG BROWSE
    // ========================================================================

    /**
     * GET /api/materials/catalog
     *
     * Browse catalog with mode switching:
     *   mode=library   -> user's library (user_material_library)
     *   mode=public    -> public catalog (visibility in [public, curated])
     *   mode=curated   -> curated only
     *   (default)      -> user's own materials + parser-sourced
     *
     * Filters: type, region_id, trust_level, recent_days, search
     */
    public function catalog(Request $request): JsonResponse
    {
        $user = auth()->user();
        $mode = $request->input('mode', 'own');
        $type = $request->input('type');
        $regionId = $request->input('region_id');
        $trustLevel = $request->input('trust_level');
        $recentDays = $request->input('recent_days');
        $search = $request->input('search');
        $perPage = min($request->input('per_page', 50), 200);

        // Default region from user settings
        if (!$regionId && $user) {
            $settings = UserSettings::where('user_id', $user->id)->first();
            if ($settings) {
                $regionId = $settings->region_id;
            }
        }

        $query = Material::where('is_active', true);

        switch ($mode) {
            case 'library':
                // User's personal library
                $libraryIds = UserMaterialLibrary::where('user_id', $user->id)
                    ->pluck('material_id');
                $query->whereIn('id', $libraryIds);
                break;

            case 'public':
                $query->whereIn('visibility', [Material::VISIBILITY_PUBLIC, Material::VISIBILITY_CURATED]);
                break;

            case 'curated':
                $query->where('visibility', Material::VISIBILITY_CURATED);
                break;

            default: // 'own'
                $query->where(function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                      ->orWhere(function ($q2) {
                          $q2->where('origin', 'parser')
                             ->whereNull('user_id');
                      });
                });
                break;
        }

        // Filters
        if ($type) {
            $query->where('type', $type);
        }
        if ($trustLevel) {
            $query->where('trust_level', $trustLevel);
        }
        if ($search) {
            $searchNorm = Material::normalizeSearchName($search);
            $query->where(function ($q) use ($searchNorm, $search) {
                $q->where('search_name', 'LIKE', "%{$searchNorm}%")
                  ->orWhere('article', 'LIKE', "%{$search}%")
                  ->orWhere('name', 'LIKE', "%{$search}%");
            });
        }

        // Eager load latest price per region
        $materials = $query->orderByDesc('updated_at')->paginate($perPage);

        // Attach latest price for region + library status
        $materialIds = $materials->pluck('id')->toArray();

        // Get latest observations per material for the selected region
        $latestPrices = $this->getLatestPricesForRegion($materialIds, $regionId);

        // Get library statuses for current user
        $libraryStatuses = [];
        if ($user) {
            $libraryStatuses = UserMaterialLibrary::where('user_id', $user->id)
                ->whereIn('material_id', $materialIds)
                ->pluck('pinned', 'material_id')
                ->toArray();
        }

        // Filter by recent_days (post-query since it depends on observations)
        $items = $materials->getCollection()->map(function ($material) use ($latestPrices, $libraryStatuses, $recentDays) {
            $latestPrice = $latestPrices[$material->id] ?? null;

            // Skip if recent_days filter active and no recent price
            if ($recentDays && $latestPrice) {
                $observedAt = $latestPrice->observed_at ?? $latestPrice->created_at;
                if ($observedAt && $observedAt->lt(now()->subDays((int) $recentDays))) {
                    return null;
                }
            }

            return [
                'id' => $material->id,
                'name' => $material->name,
                'article' => $material->article,
                'type' => $material->type,
                'unit' => $material->unit,
                'price_per_unit' => $material->price_per_unit,
                'source_url' => $material->source_url,
                'visibility' => $material->visibility,
                'trust_score' => $material->trust_score,
                'trust_level' => $material->trust_level,
                'data_origin' => $material->data_origin,
                'user_id' => $material->user_id,
                'region_id' => $material->region_id,
                'is_active' => $material->is_active,
                'created_at' => $material->created_at,
                'updated_at' => $material->updated_at,
                'price_checked_at' => $material->price_checked_at,
                // Enrichment
                'latest_price' => $latestPrice ? [
                    'price_per_unit' => $latestPrice->price_per_unit,
                    'observed_at' => $latestPrice->observed_at,
                    'source_url' => $latestPrice->source_url,
                    'region_id' => $latestPrice->region_id,
                    'is_verified' => $latestPrice->is_verified,
                    'currency' => $latestPrice->currency,
                ] : null,
                'in_library' => array_key_exists($material->id, $libraryStatuses),
                'pinned' => $libraryStatuses[$material->id] ?? false,
                // Facade fields (if applicable)
                'facade_class' => $material->facade_class,
                'metadata' => $material->metadata,
            ];
        })->filter()->values();

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $materials->currentPage(),
                'last_page' => $materials->lastPage(),
                'per_page' => $materials->perPage(),
                'total' => $materials->total(),
                'mode' => $mode,
                'region_id' => $regionId,
            ],
        ]);
    }

    // ========================================================================
    // CHECK DOMAIN SUPPORT
    // ========================================================================

    /**
     * POST /api/materials/check-domain
     *
     * Check if we have parsing selectors/rules for a given domain.
     * Returns whether auto-parsing is supported and detected material type.
     */
    public function checkDomain(Request $request): JsonResponse
    {
        $request->validate([
            'url' => 'required|url|max:2048',
        ]);

        $url = $request->input('url');
        $userId = auth()->id();

        $domainCheck = $this->domainParseService->checkDomainSupport($url, $userId);

        // Auto-detect material type from URL
        $detectedType = DomainParseService::detectMaterialType(null, $url);

        return response()->json([
            'supported' => $domainCheck['supported'],
            'source' => $domainCheck['source'], // 'chrome_ext', 'system', 'parser_config', null
            'detected_type' => $detectedType,
            'has_selectors' => !empty($domainCheck['selectors']),
            'selector_fields' => $domainCheck['selectors'] ? array_keys($domainCheck['selectors']) : [],
        ]);
    }

    // ========================================================================
    // PARSE BY URL
    // ========================================================================

    /**
     * POST /api/materials/parse-by-url
     *
     * Parse material data from a URL.
     * Returns extracted fields + duplicate candidates + parse_session_id.
     */
    public function parseByUrl(ParseByUrlRequest $request): JsonResponse
    {
        $user = auth()->user();
        $regionId = $request->input('region_id');

        // Default region from user settings
        if (!$regionId && $user) {
            $settings = UserSettings::where('user_id', $user->id)->first();
            $regionId = $settings?->region_id;
        }

        $result = $this->parseService->parseByUrl(
            $request->input('url'),
            $request->input('type'),
            $regionId
        );

        return response()->json($result);
    }

    // ========================================================================
    // STORE (catalog-aware)
    // ========================================================================

    /**
     * POST /api/materials/catalog
     *
     * Create material via catalog flow (with observation + library entry).
     */
    public function store(StoreCatalogMaterialRequest $request): JsonResponse
    {
        $user = auth()->user();
        $validated = $request->validated();

        // Default region from user settings
        $regionId = $validated['observation_region_id'] ?? $validated['region_id'] ?? null;
        if (!$regionId) {
            $settings = UserSettings::where('user_id', $user->id)->first();
            $regionId = $settings?->region_id;
        }

        // Build material data
        $materialData = collect($validated)->only([
            'name', 'article', 'type', 'unit', 'price_per_unit', 'source_url',
            'thickness', 'waste_factor', 'length_mm', 'width_mm', 'thickness_mm',
            'material_tag', 'region_id', 'visibility', 'metadata', 'operation_ids',
            'facade_class', 'facade_base_type', 'facade_thickness_mm', 'facade_covering',
            'facade_cover_type', 'facade_collection', 'facade_price_group_label',
            'facade_decor_label', 'facade_article_optional',
        ])->toArray();

        $materialData['data_origin'] = $validated['data_origin'] ?? Material::ORIGIN_MANUAL;

        // Build observation data
        $observationData = [
            'price_per_unit' => $validated['price_per_unit'],
            'source_url' => $validated['source_url'],
            'region_id' => $regionId,
            'source_type' => $validated['observation_source_type'] ?? 'manual',
            'screenshot_path' => $validated['screenshot_path'] ?? null,
            'snapshot_path' => $validated['snapshot_path'] ?? null,
            'currency' => 'RUB',
        ];

        $material = $this->parseService->createMaterialWithObservation(
            $materialData,
            $observationData,
            $user->id,
            $validated['parse_session_id'] ?? null
        );

        // Auto-add to user library
        UserMaterialLibrary::firstOrCreate([
            'user_id' => $user->id,
            'material_id' => $material->id,
        ]);

        Log::info('MaterialCatalog.store', ['material_id' => $material->id, 'user_id' => $user->id]);

        return response()->json($material, 201);
    }

    // ========================================================================
    // SHOW DETAIL
    // ========================================================================

    /**
     * GET /api/materials/catalog/{id}
     *
     * Get detailed material info with trust score breakdown.
     */
    public function show(int $id): JsonResponse
    {
        $material = Material::findOrFail($id);
        $user = auth()->user();

        // Access check: own, public, curated, or in library
        $isAccessible = $material->user_id === $user->id
            || in_array($material->visibility, [Material::VISIBILITY_PUBLIC, Material::VISIBILITY_CURATED])
            || UserMaterialLibrary::where('user_id', $user->id)->where('material_id', $id)->exists();

        if (!$isAccessible) {
            return response()->json(['error' => 'Нет доступа к материалу'], 403);
        }

        // Get observations
        $observations = MaterialPriceHistory::where('material_id', $material->id)
            ->orderByDesc('observed_at')
            ->get();

        // Compute trust breakdown
        $trustBreakdown = $this->computeTrustBreakdown($material, $observations);

        // Latest price
        $latestObs = $observations->first();

        return response()->json([
            'material' => [
                'id' => $material->id,
                'name' => $material->name,
                'article' => $material->article,
                'type' => $material->type,
                'unit' => $material->unit,
                'price_per_unit' => $material->price_per_unit,
                'source_url' => $material->source_url,
                'visibility' => $material->visibility,
                'trust_score' => $material->trust_score,
                'trust_level' => $material->trust_level,
                'data_origin' => $material->data_origin,
                'user_id' => $material->user_id,
                'region_id' => $material->region_id,
                'is_active' => $material->is_active,
                'thickness' => $material->thickness,
                'thickness_mm' => $material->thickness_mm,
                'length_mm' => $material->length_mm,
                'width_mm' => $material->width_mm,
                'waste_factor' => $material->waste_factor,
                'material_tag' => $material->material_tag,
                'created_at' => $material->created_at,
                'updated_at' => $material->updated_at,
                'price_checked_at' => $material->price_checked_at,
                'last_parsed_at' => $material->last_parsed_at,
                'last_parse_status' => $material->last_parse_status,
                'last_parse_error' => $material->last_parse_error,
                'metadata' => $material->metadata,
                'latest_price' => $latestObs ? [
                    'price_per_unit' => $latestObs->price_per_unit,
                    'observed_at' => $latestObs->observed_at,
                    'source_url' => $latestObs->source_url,
                    'is_verified' => $latestObs->is_verified,
                    'currency' => $latestObs->currency,
                ] : null,
                'observation_count' => $observations->count(),
            ],
            'trust_breakdown' => $trustBreakdown,
        ]);
    }

    /**
     * Compute trust score breakdown for display.
     */
    protected function computeTrustBreakdown(Material $material, $observations): array
    {
        $breakdown = [];

        // +40: at least 1 verified observation within 30 days
        $recentVerified = $observations->filter(fn($o) =>
            $o->is_verified && $o->observed_at && $o->observed_at->gte(now()->subDays(30))
        );
        $breakdown[] = [
            'label' => 'Верифицированная цена (30 дней)',
            'points' => $recentVerified->isNotEmpty() ? 40 : 0,
            'max' => 40,
            'met' => $recentVerified->isNotEmpty(),
            'description' => 'Хотя бы 1 подтверждённое наблюдение цены за последние 30 дней',
        ];

        // +20: >=2 independent domains
        $recentObs = $observations->filter(fn($o) =>
            $o->observed_at && $o->observed_at->gte(now()->subDays(60)) && $o->source_url
        );
        $domains = $recentObs->map(fn($o) => parse_url($o->source_url, PHP_URL_HOST))->filter()->unique();
        $breakdown[] = [
            'label' => 'Независимые источники',
            'points' => $domains->count() >= 2 ? 20 : 0,
            'max' => 20,
            'met' => $domains->count() >= 2,
            'description' => "Нужно ≥2 разных домена за 60 дней (сейчас: {$domains->count()})",
        ];

        // +15: complete data + non-manual origin
        $hasCompleteData = !empty($material->name) && !empty($material->article)
            && !empty($material->unit) && !empty($material->type);
        $hasVerifiedOrigin = $material->data_origin !== Material::ORIGIN_MANUAL
            || $observations->where('is_verified', true)->isNotEmpty();
        $met = $hasCompleteData && $hasVerifiedOrigin;
        $breakdown[] = [
            'label' => 'Полнота данных',
            'points' => $met ? 15 : 0,
            'max' => 15,
            'met' => $met,
            'description' => 'Заполнены: название, артикул, ед. изм., тип + источник не ручной',
        ];

        // +10: snapshot
        $latestObs = $observations->first();
        $hasSnapshot = $latestObs && ($latestObs->snapshot_path || $latestObs->screenshot_path);
        $breakdown[] = [
            'label' => 'Скриншот/снимок страницы',
            'points' => $hasSnapshot ? 10 : 0,
            'max' => 10,
            'met' => (bool) $hasSnapshot,
            'description' => 'Последнее наблюдение имеет прикреплённый скриншот',
        ];

        // -25: parse fail streak
        $meta = $material->metadata ?? [];
        $failStreak = $meta['parse_fail_streak'] ?? 0;
        $hasFails = in_array($material->last_parse_status, [Material::PARSE_FAILED, Material::PARSE_BLOCKED])
            && $failStreak >= 3;
        $breakdown[] = [
            'label' => 'Ошибки парсинга',
            'points' => $hasFails ? -25 : 0,
            'max' => 0,
            'met' => !$hasFails,
            'description' => $hasFails
                ? "Серия неудачных парсингов: {$failStreak} подряд"
                : 'Нет серии ошибок парсинга',
        ];

        // -20: stale price
        $isStale = false;
        if ($latestObs) {
            $observedAt = $latestObs->observed_at ?? $latestObs->created_at;
            $isStale = $observedAt && $observedAt->lt(now()->subDays(90));
        } else {
            $isStale = true;
        }
        $breakdown[] = [
            'label' => 'Актуальность цены',
            'points' => $isStale ? -20 : 0,
            'max' => 0,
            'met' => !$isStale,
            'description' => $isStale
                ? 'Последняя цена старше 90 дней (или нет наблюдений)'
                : 'Цена актуальна (менее 90 дней)',
        ];

        return $breakdown;
    }

    // ========================================================================
    // UPDATE MATERIAL
    // ========================================================================

    /**
     * PUT /api/materials/catalog/{id}
     *
     * Update material parameters (owner or curator only).
     */
    public function updateMaterial(Request $request, int $id): JsonResponse
    {
        $material = Material::findOrFail($id);
        $user = auth()->user();

        if ($material->user_id !== $user->id && $material->curator_user_id !== $user->id) {
            return response()->json(['error' => 'Нет прав на редактирование'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:500',
            'article' => 'nullable|string|max:255',
            'type' => 'sometimes|required|string|in:plate,edge,facade,hardware',
            'unit' => 'sometimes|required|string|max:20',
            'source_url' => 'nullable|url|max:2048',
            'visibility' => 'nullable|string|in:private,public,curated',
            'thickness_mm' => 'nullable|numeric|min:0|max:999',
            'length_mm' => 'nullable|integer|min:0|max:99999',
            'width_mm' => 'nullable|integer|min:0|max:99999',
            'waste_factor' => 'nullable|numeric|min:0|max:1',
            'material_tag' => 'nullable|string|max:100',
            'region_id' => 'nullable|integer|exists:regions,id',
        ]);

        // Update thickness decimal from thickness_mm
        if (array_key_exists('thickness_mm', $validated)) {
            $validated['thickness'] = $validated['thickness_mm'] ? round($validated['thickness_mm'], 2) : null;
        }

        $material->update($validated);

        // Recalculate trust after data change
        $this->trustScoreService->recalculate($material);

        return response()->json([
            'message' => 'Материал обновлён',
            'material' => $material->fresh(),
        ]);
    }

    // ========================================================================
    // REFRESH (re-parse)
    // ========================================================================

    /**
     * POST /api/materials/{id}/refresh
     *
     * Re-parse material from its source URL.
     */
    public function refresh(Request $request, int $id): JsonResponse
    {
        $material = Material::findOrFail($id);
        $user = auth()->user();

        // Check access: owner, curator, or admin
        if ($material->user_id !== $user->id && $material->curator_user_id !== $user->id) {
            return response()->json(['error' => 'Нет прав на обновление'], 403);
        }

        $regionId = $request->input('region_id');
        if (!$regionId) {
            $settings = UserSettings::where('user_id', $user->id)->first();
            $regionId = $settings?->region_id;
        }

        $result = $this->parseService->refreshMaterial($material, $regionId);

        return response()->json($result);
    }

    // ========================================================================
    // PRICE OBSERVATIONS
    // ========================================================================

    /**
     * GET /api/materials/{id}/price-observations
     *
     * List price observations for a material, filterable by region.
     */
    public function priceObservations(Request $request, int $id): JsonResponse
    {
        $material = Material::findOrFail($id);
        $regionId = $request->input('region_id');

        $query = MaterialPriceHistory::where('material_id', $id)
            ->orderByDesc('observed_at');

        if ($regionId) {
            $query->where(function ($q) use ($regionId) {
                $q->where('region_id', $regionId)
                  ->orWhereNull('region_id');
            });
        }

        $observations = $query->get()->map(function ($obs) {
            return [
                'id' => $obs->id,
                'price_per_unit' => $obs->price_per_unit,
                'source_url' => $obs->source_url,
                'observed_at' => $obs->observed_at,
                'valid_from' => $obs->valid_from,
                'region_id' => $obs->region_id,
                'source_type' => $obs->source_type,
                'is_verified' => $obs->is_verified,
                'currency' => $obs->currency,
                'availability' => $obs->availability,
                'screenshot_path' => $obs->screenshot_path,
                'snapshot_path' => $obs->snapshot_path,
                'created_at' => $obs->created_at,
            ];
        });

        return response()->json([
            'material_id' => $id,
            'observations' => $observations,
        ]);
    }

    /**
     * POST /api/materials/{id}/price-observations
     *
     * Add a manual price observation to an existing material.
     */
    public function addPriceObservation(AddPriceObservationRequest $request, int $id): JsonResponse
    {
        $material = Material::findOrFail($id);
        $user = auth()->user();
        $validated = $request->validated();

        // Default region
        $regionId = $validated['region_id'] ?? null;
        if (!$regionId) {
            $settings = UserSettings::where('user_id', $user->id)->first();
            $regionId = $settings?->region_id;
        }

        $observation = MaterialPriceHistory::create([
            'material_id' => $material->id,
            'version' => $material->version,
            'price_per_unit' => $validated['price_per_unit'],
            'source_url' => $validated['source_url'],
            'valid_from' => now()->toDateString(),
            'observed_at' => now(),
            'region_id' => $regionId,
            'source_type' => $validated['source_type'] ?? 'manual',
            'is_verified' => ($validated['source_type'] ?? 'manual') !== 'manual' ? 1 : 0,
            'currency' => $validated['currency'] ?? 'RUB',
            'availability' => $validated['availability'] ?? null,
            'screenshot_path' => $validated['screenshot_path'] ?? null,
            'snapshot_path' => $validated['snapshot_path'] ?? null,
        ]);

        // Update material's current price if this is newer
        $material->update([
            'price_per_unit' => $validated['price_per_unit'],
            'price_checked_at' => now(),
        ]);

        // Recalculate trust score
        $this->trustScoreService->recalculate($material);

        Log::info('MaterialCatalog.addPriceObservation', [
            'material_id' => $material->id,
            'observation_id' => $observation->id,
            'user_id' => $user->id,
        ]);

        return response()->json($observation, 201);
    }

    // ========================================================================
    // USER LIBRARY
    // ========================================================================

    /**
     * POST /api/materials/{id}/library
     *
     * Add material to user's personal library.
     */
    public function addToLibrary(Request $request, int $id): JsonResponse
    {
        $material = Material::findOrFail($id);
        $user = auth()->user();

        // Check visibility access
        if ($material->visibility === Material::VISIBILITY_PRIVATE && $material->user_id !== $user->id) {
            return response()->json(['error' => 'Материал недоступен'], 403);
        }

        $entry = UserMaterialLibrary::firstOrCreate(
            ['user_id' => $user->id, 'material_id' => $material->id],
            [
                'preferred_region_id' => $request->input('preferred_region_id'),
                'notes' => $request->input('notes'),
            ]
        );

        return response()->json([
            'message' => 'Материал добавлен в библиотеку',
            'entry' => $entry,
        ]);
    }

    /**
     * DELETE /api/materials/{id}/library
     *
     * Remove material from user's library.
     */
    public function removeFromLibrary(int $id): JsonResponse
    {
        $user = auth()->user();

        $deleted = UserMaterialLibrary::where('user_id', $user->id)
            ->where('material_id', $id)
            ->delete();

        return response()->json([
            'message' => $deleted ? 'Удалено из библиотеки' : 'Не найдено в библиотеке',
        ]);
    }

    /**
     * PATCH /api/materials/{id}/library
     *
     * Toggle pin / update notes in user's library entry.
     */
    public function updateLibraryEntry(Request $request, int $id): JsonResponse
    {
        $user = auth()->user();

        $entry = UserMaterialLibrary::where('user_id', $user->id)
            ->where('material_id', $id)
            ->firstOrFail();

        $entry->update($request->only(['pinned', 'notes', 'preferred_region_id', 'preferred_price_source_url']));

        return response()->json($entry);
    }

    // ========================================================================
    // MERGE (admin/curator)
    // ========================================================================

    /**
     * POST /api/materials/merge
     *
     * Merge duplicate material into primary.
     * Only available to owner/curator/admin.
     */
    public function merge(Request $request): JsonResponse
    {
        $request->validate([
            'primary_id' => 'required|integer|exists:materials,id',
            'duplicate_id' => 'required|integer|exists:materials,id|different:primary_id',
        ]);

        $user = auth()->user();
        $primary = Material::findOrFail($request->input('primary_id'));

        // Check permission: must be owner/curator of primary or admin
        $isOwner = $primary->user_id === $user->id;
        $isCurator = $primary->curator_user_id === $user->id;
        // Simple admin check (could be enhanced with roles)
        if (!$isOwner && !$isCurator) {
            return response()->json(['error' => 'Нет прав на слияние'], 403);
        }

        try {
            $result = $this->dedupService->merge(
                $request->input('primary_id'),
                $request->input('duplicate_id')
            );

            $this->trustScoreService->recalculate($result);

            return response()->json([
                'message' => 'Материалы объединены',
                'material' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    // ========================================================================
    // TRUST SCORE
    // ========================================================================

    /**
     * POST /api/materials/{id}/recalculate-trust
     *
     * Force recalculate trust score for a material.
     */
    public function recalculateTrust(int $id): JsonResponse
    {
        $material = Material::findOrFail($id);
        $material = $this->trustScoreService->recalculate($material);

        return response()->json([
            'trust_score' => $material->trust_score,
            'trust_level' => $material->trust_level,
        ]);
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    /**
     * Get latest price observation per material for a given region.
     *
     * @return array  [material_id => MaterialPriceHistory]
     */
    protected function getLatestPricesForRegion(array $materialIds, ?int $regionId): array
    {
        if (empty($materialIds)) return [];

        $query = MaterialPriceHistory::whereIn('material_id', $materialIds);

        if ($regionId) {
            // Prefer region-specific, fallback to region=null
            $query->where(function ($q) use ($regionId) {
                $q->where('region_id', $regionId)
                  ->orWhereNull('region_id');
            });
        }

        $observations = $query->orderByDesc('observed_at')->get();

        // Group by material_id, take first (latest) per material
        $result = [];
        foreach ($observations as $obs) {
            if (!isset($result[$obs->material_id])) {
                $result[$obs->material_id] = $obs;
            }
        }

        return $result;
    }
}
