<?php

namespace App\Services;

use App\Models\Material;
use App\Models\MaterialPriceHistory;
use App\Models\ParsingSession;
use App\Models\ParsingLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MaterialParseService
{
    protected TrustScoreService $trustScoreService;
    protected MaterialDeduplicationService $dedupService;
    protected DomainParseService $domainParseService;

    public function __construct(
        TrustScoreService $trustScoreService,
        MaterialDeduplicationService $dedupService,
        DomainParseService $domainParseService
    ) {
        $this->trustScoreService = $trustScoreService;
        $this->dedupService = $dedupService;
        $this->domainParseService = $domainParseService;
    }

    /**
     * Parse material data from a URL.
     * Creates a parsing session for traceability.
     *
     * @param string $url
     * @param string $type
     * @param int|null $regionId
     * @return array  { success, data, duplicates, parse_session_id, message }
     */
    public function parseByUrl(string $url, string $type, ?int $regionId = null): array
    {
        $normalizedUrl = MaterialDeduplicationService::normalizeUrl($url);

        // Create parsing session for traceability
        $session = ParsingSession::create([
            'supplier_name' => parse_url($url, PHP_URL_HOST) ?? 'unknown',
            'started_at' => now(),
            'status' => 'running',
            'lifecycle_status' => 'created',
        ]);

        try {
            // Step 1: Check for duplicates
            $duplicates = $this->dedupService->findDuplicates($url, null, null, null, $type);

            // Step 2: Try domain-specific selectors first, then fallback to generic
            $userId = auth()->id();
            $domainCheck = $this->domainParseService->checkDomainSupport($url, $userId);
            $parseSource = 'generic';

            if ($domainCheck['supported']) {
                $selectorResult = $this->domainParseService->parseWithSelectors($url, $domainCheck['selectors']);
                if ($selectorResult['success'] && $selectorResult['data']) {
                    $pageData = [
                        'status' => 'fetched',
                        'name' => $selectorResult['data']['name'],
                        'article' => $selectorResult['data']['article'],
                        'price_per_unit' => $selectorResult['data']['price_per_unit'],
                        'type' => $type,
                        'unit' => null,
                        'message' => $selectorResult['message'],
                    ];
                    $parseSource = 'selectors';
                } else {
                    // Selectors failed, fallback to generic
                    Log::info('DomainParseService selectors failed, falling back to generic', ['url' => $url]);
                    $pageData = $this->fetchPageData($url);
                }
            } else {
                $pageData = $this->fetchPageData($url);
            }

            // Log parse results
            ParsingLog::create([
                'session_id' => $session->id,
                'url' => $url,
                'level' => $pageData['status'] === 'fetched' ? 'info' : 'warning',
                'message' => json_encode([
                    'status' => $pageData['status'],
                    'extracted' => [
                        'name' => $pageData['name'],
                        'article' => $pageData['article'],
                        'price' => $pageData['price_per_unit'],
                        'unit' => $pageData['unit'],
                    ],
                ]),
            ]);

            // Step 3: After getting article/name, refine duplicate search
            if ($pageData['article'] || $pageData['name']) {
                $refinedDuplicates = $this->dedupService->findDuplicates(
                    $url,
                    $pageData['article'],
                    $pageData['name'],
                    $pageData['unit'],
                    $type
                );
                if ($refinedDuplicates->isNotEmpty()) {
                    $duplicates = $refinedDuplicates;
                }
            }

            // Complete session
            $session->update([
                'status' => 'completed',
                'lifecycle_status' => 'finished_success',
                'finished_at' => now(),
                'result_status' => $pageData['status'] === 'fetched' ? 'success' : 'partial',
            ]);

            // Build confidence score
            $confidence = $this->calculateConfidence($pageData);
            $isPresent = static fn($v): bool => !($v === null || $v === '');
            $filledFields = collect([
                'name' => $pageData['name'] ?? null,
                'article' => $pageData['article'] ?? null,
                'price_per_unit' => $pageData['price_per_unit'] ?? null,
            ])->filter($isPresent);
            $missingFields = ['name', 'article', 'price_per_unit'];
            $missingFields = array_values(array_filter(
                $missingFields,
                fn($field) => !$isPresent($pageData[$field] ?? null)
            ));

            $parseStatus = 'ok';
            if (($pageData['status'] ?? null) !== 'fetched') {
                $parseStatus = ($pageData['status'] ?? 'error') === 'blocked' ? 'blocked' : 'error';
            } elseif ($filledFields->count() === 0) {
                $parseStatus = 'no_fields';
            } elseif ($filledFields->count() < 3) {
                $parseStatus = 'partial';
            }

            $message = $pageData['message'] ?? 'Данные получены';
            if ($parseStatus === 'no_fields') {
                $message = $domainCheck['supported']
                    ? 'Домен поддерживается, но поля не извлечены. Возможно, селекторы устарели или контент загружается динамически.'
                    : 'Не удалось извлечь поля со страницы. Проверьте URL или заполните данные вручную.';
            } elseif ($parseStatus === 'partial') {
                $message = 'Извлечены не все поля. Проверьте значения перед сохранением.';
            }

            return [
                'success' => true,
                'data' => [
                    'name' => $pageData['name'],
                    'article' => $pageData['article'],
                    'price_per_unit' => $pageData['price_per_unit'],
                    'type' => $type,
                    'unit' => $pageData['unit'] ?? $this->guessUnit($type),
                    'source_url' => $url,
                ],
                'duplicates' => $duplicates->map(fn($d) => [
                    'material' => $d['material'],
                    'reason' => $d['reason'],
                    'confidence' => $d['confidence'],
                ])->values()->toArray(),
                'parse_session_id' => $session->id,
                'confidence' => $confidence,
                'has_selectors' => $domainCheck['supported'],
                'parse_source' => $parseSource,
                'parse_status' => $parseStatus,
                'diagnostics' => [
                    'page_status' => $pageData['status'] ?? null,
                    'filled_fields' => $filledFields->keys()->values()->toArray(),
                    'missing_fields' => $missingFields,
                    'domain_supported' => (bool) $domainCheck['supported'],
                    'domain_profile' => $domainCheck['profile'] ? [
                        'id' => $domainCheck['profile']->id,
                        'name' => $domainCheck['profile']->name,
                        'source' => $domainCheck['profile']->source,
                        'is_default' => (bool) $domainCheck['profile']->is_default,
                        'version' => $domainCheck['profile']->version,
                        'user_id' => $domainCheck['profile']->user_id,
                    ] : null,
                ],
                'message' => $message,
            ];
        } catch (\Throwable $e) {
            $session->update([
                'status' => 'failed',
                'lifecycle_status' => 'finished_failed',
                'finished_at' => now(),
                'failed_reason' => $e->getMessage(),
                'failed_at' => now(),
            ]);

            ParsingLog::create([
                'session_id' => $session->id,
                'url' => $url,
                'level' => 'error',
                'message' => $e->getMessage(),
            ]);

            Log::error('MaterialParseService.parseByUrl failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'data' => null,
                'duplicates' => [],
                'parse_session_id' => $session->id,
                'confidence' => 0,
                'parse_status' => 'error',
                'diagnostics' => [
                    'page_status' => 'exception',
                    'filled_fields' => [],
                    'missing_fields' => ['name', 'article', 'price_per_unit'],
                    'domain_supported' => false,
                ],
                'message' => 'Ошибка парсинга: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Create a material + first observation from parsed data.
     *
     * @param array $materialData  Material fields
     * @param array $observationData  Observation fields (price, region_id, etc.)
     * @param int $userId
     * @param int|null $parseSessionId
     * @return Material
     */
    public function createMaterialWithObservation(
        array $materialData,
        array $observationData,
        int $userId,
        ?int $parseSessionId = null
    ): Material {
        // Create material
        $material = Material::create(array_merge($materialData, [
            'user_id' => $userId,
            'origin' => 'user',
            'is_active' => true,
            'version' => 1,
            'visibility' => Material::VISIBILITY_PRIVATE,
        ]));

        // Create first price observation
        MaterialPriceHistory::create([
            'material_id' => $material->id,
            'version' => 1,
            'price_per_unit' => $observationData['price_per_unit'] ?? $material->price_per_unit,
            'source_url' => $observationData['source_url'] ?? $material->source_url,
            'valid_from' => now()->toDateString(),
            'observed_at' => now(),
            'region_id' => $observationData['region_id'] ?? null,
            'source_type' => $observationData['source_type'] ?? 'manual',
            'parse_session_id' => $parseSessionId,
            'is_verified' => ($observationData['source_type'] ?? 'manual') !== 'manual' ? 1 : 0,
            'currency' => $observationData['currency'] ?? 'RUB',
            'screenshot_path' => $observationData['screenshot_path'] ?? null,
            'snapshot_path' => $observationData['snapshot_path'] ?? null,
        ]);

        // Calculate initial trust score
        $this->trustScoreService->recalculate($material);

        return $material->fresh();
    }

    /**
     * Refresh a material by re-parsing its source URL.
     */
    public function refreshMaterial(Material $material, ?int $regionId = null): array
    {
        $url = $material->source_url;
        if (!$url) {
            // Try last observation URL
            $lastObs = $material->priceHistories()->orderByDesc('observed_at')->first();
            $url = $lastObs?->source_url;
        }

        if (!$url) {
            return [
                'success' => false,
                'message' => 'Нет URL для обновления',
            ];
        }

        $result = $this->parseByUrl($url, $material->type, $regionId);

        if ($result['success'] && $result['data']) {
            $data = $result['data'];

            // Update material parse status
            $material->update([
                'last_parsed_at' => now(),
                'last_parse_status' => Material::PARSE_OK,
                'last_parse_error' => null,
            ]);

            // Optionally update name/article if changed
            $updateFields = [];
            if ($data['name'] && !$material->name) {
                $updateFields['name'] = $data['name'];
            }
            if ($data['article'] && !$material->article) {
                $updateFields['article'] = $data['article'];
            }
            if (!empty($updateFields)) {
                $material->update($updateFields);
            }

            // Create new observation if price found
            if ($data['price_per_unit']) {
                MaterialPriceHistory::create([
                    'material_id' => $material->id,
                    'version' => $material->version,
                    'price_per_unit' => $data['price_per_unit'],
                    'source_url' => $url,
                    'valid_from' => now()->toDateString(),
                    'observed_at' => now(),
                    'region_id' => $regionId,
                    'source_type' => 'web',
                    'parse_session_id' => $result['parse_session_id'],
                    'is_verified' => 1,
                    'currency' => 'RUB',
                ]);

                // Update material price
                $material->update([
                    'price_per_unit' => $data['price_per_unit'],
                    'price_checked_at' => now(),
                ]);
            }

            // Recalculate trust
            $this->trustScoreService->recalculate($material);

            return [
                'success' => true,
                'message' => 'Материал обновлён',
                'material' => $material->fresh(),
                'price_updated' => (bool) $data['price_per_unit'],
            ];
        }

        // Parse failed
        $meta = $material->metadata ?? [];
        $meta['parse_fail_streak'] = ($meta['parse_fail_streak'] ?? 0) + 1;
        $material->update([
            'last_parsed_at' => now(),
            'last_parse_status' => Material::PARSE_FAILED,
            'last_parse_error' => $result['message'] ?? 'Unknown error',
            'metadata' => $meta,
        ]);

        $this->trustScoreService->recalculate($material);

        return [
            'success' => false,
            'message' => $result['message'],
            'material' => $material->fresh(),
        ];
    }

    /**
     * Fetch and extract data from a page URL.
     * Delegates to MaterialController's logic (reuses the existing page scraping).
     */
    protected function fetchPageData(string $url): array
    {
        // Reuse the controller's fetchByUrl endpoint internally
        $controller = app(\App\Http\Controllers\Api\MaterialController::class);

        // Use reflection to call the private method
        $reflection = new \ReflectionMethod($controller, 'fetchPageData');
        $reflection->setAccessible(true);

        return $reflection->invoke($controller, $url);
    }

    /**
     * Calculate confidence score for parsed data.
     */
    protected function calculateConfidence(array $pageData): int
    {
        $score = 0;

        if (!empty($pageData['name'])) $score += 30;
        if (!empty($pageData['article'])) $score += 25;
        if (!empty($pageData['price_per_unit'])) $score += 30;
        if (!empty($pageData['unit'])) $score += 15;

        return $score;
    }

    /**
     * Guess unit for a given material type.
     */
    protected function guessUnit(string $type): string
    {
        return match ($type) {
            'plate' => 'м²',
            'edge' => 'м.п.',
            'facade' => 'шт',
            'hardware' => 'шт',
            default => 'шт',
        };
    }
}
