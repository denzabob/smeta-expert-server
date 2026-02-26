<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupplierUrl;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class UrlCollectionController extends Controller
{
    /**
     * Сохранить собранные URL из Python парсера.
     *
     * POST /api/parsing/save-urls
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function saveUrls(Request $request): JsonResponse
    {
        // Валидация HMAC
        $this->validateHmac($request);
        
        // Валидация входных данных
        $validator = Validator::make($request->all(), [
            'supplier_name' => 'required|string|max:255',
            'urls' => 'required|array|min:1',
            'urls.*.url' => 'required|string',
            'urls.*.is_valid' => 'required|boolean',
            'urls.*.material_type' => 'nullable|string|max:255',
            'urls.*.validation_error' => 'nullable|string',
            'collected_at' => 'nullable|date',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }
        
        $supplierName = $request->input('supplier_name');
        $urls = $request->input('urls');
        $collectedAt = $request->input('collected_at', now());
        
        $savedCount = 0;
        $updatedCount = 0;
        $failedCount = 0;

        foreach ($urls as $urlData) {
            try {
                $existing = SupplierUrl::where('supplier_name', $supplierName)
                    ->where('url', $urlData['url'])
                    ->first();

                if ($existing) {
                    // UPSERT для существующего URL: обновляем только last_seen_at
                    // НЕ трогаем status — его сбросит reset после collect
                    $existing->update([
                        'material_type' => $urlData['material_type'] ?? $existing->material_type,
                        'is_valid' => $urlData['is_valid'],
                        'validation_error' => $urlData['validation_error'] ?? null,
                        'last_seen_at' => now(),
                        // status НЕ меняем!
                    ]);
                    $updatedCount++;
                } else {
                    // Новый URL: status=pending, attempts=0
                    SupplierUrl::create([
                        'supplier_name' => $supplierName,
                        'url' => $urlData['url'],
                        'material_type' => $urlData['material_type'] ?? null,
                        'is_valid' => $urlData['is_valid'],
                        'validation_error' => $urlData['validation_error'] ?? null,
                        'collected_at' => $collectedAt,
                        'validated_at' => now(),
                        'last_seen_at' => now(),
                        'status' => SupplierUrl::STATUS_PENDING,
                        'attempts' => 0,
                    ]);
                    $savedCount++;
                }
            } catch (\Exception $e) {
                $failedCount++;
                Log::error("Failed to save URL: {$urlData['url']}", [
                    'error' => $e->getMessage(),
                    'supplier' => $supplierName,
                ]);
            }
        }
        
        Log::info("URL collection saved", [
            'supplier' => $supplierName,
            'saved' => $savedCount,
            'updated' => $updatedCount,
            'failed' => $failedCount,
            'total' => count($urls),
        ]);

        $total = count($urls);
        $writeFailed = $failedCount > 0;
        $nothingWritten = ($savedCount + $updatedCount) === 0 && $total > 0;

        if ($writeFailed || $nothingWritten) {
            $reason = $writeFailed ? 'COLLECT_DB_SCHEMA_MISMATCH' : 'COLLECT_NO_ROWS_WRITTEN';
            Log::error('URL collection failed - aborting full scan', [
                'supplier' => $supplierName,
                'reason' => $reason,
                'saved' => $savedCount,
                'updated' => $updatedCount,
                'failed' => $failedCount,
                'total' => $total,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'URL collection failed: DB write error or zero rows written',
                'reason' => $reason,
                'stats' => [
                    'saved' => $savedCount,
                    'updated' => $updatedCount,
                    'failed' => $failedCount,
                    'total' => $total,
                ],
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'URLs saved successfully',
            'received_total' => count($urls),
            'unique_total' => $savedCount + $updatedCount,  // Unique by (supplier, url)
            'inserted_count' => $savedCount,
            'updated_count' => $updatedCount,
            'failed_count' => $failedCount,
            'errors_sample' => [],  // Could add first N errors if needed
        ]);
    }
    
    /**
     * Получить актуальные URL для поставщика.
     *
     * GET /api/parsing/get-urls/{supplier}
     *
     * @param string $supplier
     * @param Request $request
     * @return JsonResponse
     */
    public function getUrls(string $supplier, Request $request): JsonResponse
    {
        $materialType = $request->query('material_type');
        $onlyValid = $request->query('only_valid', true);
        
        $query = SupplierUrl::where('supplier_name', $supplier);
        
        if ($materialType) {
            $query->where('material_type', $materialType);
        }
        
        if ($onlyValid) {
            $query->valid();
        }
        
        $urls = $query
            ->orderBy('material_type')
            ->orderBy('collected_at', 'desc')
            ->get()
            ->groupBy('material_type')
            ->map(function ($group) {
                return $group->pluck('url')->toArray();
            });
        
        return response()->json([
            'success' => true,
            'supplier' => $supplier,
            'urls' => $urls,
            'total' => $urls->flatten()->count(),
        ]);
    }
    
    /**
     * Запустить сбор URL для поставщика вручную.
     *
     * POST /api/parsing/collect-urls/{supplier}
     *
     * @param string $supplier
     * @return JsonResponse
     */
    public function collectUrls(string $supplier, Request $request): JsonResponse
    {
        try {
            // Block if there is an active session for this supplier
            $existingSession = \App\Models\ParsingSession::where('supplier_name', $supplier)
                ->whereNotIn('lifecycle_status', \App\Models\ParsingSession::TERMINAL_STATUSES)
                ->first();

            if ($existingSession) {
                return response()->json([
                    'success' => false,
                    'message' => "Active session already exists for supplier '{$supplier}' (session_id={$existingSession->id}, status={$existingSession->getLifecycleStatus()}).",
                    'existing_session_id' => $existingSession->id,
                ], 409);
            }

            // Create a collect-only session so it appears in history
            $session = \App\Models\ParsingSession::create([
                'supplier_name' => $supplier,
                'status' => \App\Models\ParsingSession::DB_STATUS_PENDING,
                'lifecycle_status' => \App\Models\ParsingSession::STATUS_CREATED,
                'full_scan_run_id' => 'collect_only',
            ]);

            $session->startCollecting();

            // Не создаем лог здесь, так как поле url обязательное
            // Python скрипт создаст первый лог при запуске

            $profileId = $request->input('profile_id');
            $profile = null;
            if ($profileId) {
                $profile = \App\Models\ParserSupplierCollectProfile::where('supplier_name', $supplier)
                    ->where('id', $profileId)
                    ->first();
            }

            $overrideJson = null;
            if ($profile) {
                $overrideJson = base64_encode(json_encode($profile->config_override, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            // Запускаем Artisan команду асинхронно
            $params = [
                'supplier' => $supplier,
                '--async' => true,
            ];

            if ($overrideJson) {
                $params['--config-override-base64'] = $overrideJson;
            }

            $params['--session'] = $session->id;
            // Используем внутренний URL для Docker контейнера
            $params['--api-url'] = env('PARSER_INTERNAL_API_URL', rtrim(config('app.url'), '/') . '/api');

            \Illuminate\Support\Facades\Artisan::call('collect:urls', $params);
            
            return response()->json([
                'success' => true,
                'message' => "URL collection started for {$supplier}",
                'profile_id' => $profile?->id,
                'session_id' => $session->id,
            ]);
            
        } catch (\Exception $e) {
            Log::error("Failed to start URL collection", [
                'supplier' => $supplier,
                'error' => $e->getMessage(),
            ]);

            if (isset($session) && $session instanceof \App\Models\ParsingSession) {
                $session->markAsFailedWithReason('COLLECT_START_FAILED', $e->getMessage());
                // Не создаем лог с пустым url, используем addLog метод сессии
                $session->addLog('error', "Collect URLs failed to start: {$e->getMessage()}");
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to start URL collection',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Получить статистику по URL поставщика.
     *
     * GET /api/parsing/url-stats/{supplier}
     *
     * @param string $supplier
     * @return JsonResponse
     */
    public function getStats(string $supplier): JsonResponse
    {
        $total = SupplierUrl::forSupplier($supplier)->count();
        $valid = SupplierUrl::forSupplier($supplier)->valid()->count();
        $invalid = SupplierUrl::forSupplier($supplier)->invalid()->count();
        
        $byMaterialType = SupplierUrl::forSupplier($supplier)
            ->valid()
            ->selectRaw('material_type, COUNT(*) as count')
            ->groupBy('material_type')
            ->pluck('count', 'material_type');
        
        $lastCollection = SupplierUrl::forSupplier($supplier)
            ->orderBy('collected_at', 'desc')
            ->first();
        
        return response()->json([
            'success' => true,
            'supplier' => $supplier,
            'stats' => [
                'total' => $total,
                'valid' => $valid,
                'invalid' => $invalid,
                'by_material_type' => $byMaterialType,
                'last_collection_at' => $lastCollection?->collected_at?->toIso8601String(),
            ],
        ]);
    }
    
    /**
     * Валидация HMAC подписи.
     *
     * @param Request $request
     * @throws \Illuminate\Validation\UnauthorizedException
     */
    protected function validateHmac(Request $request): void
    {
        $hmacSecret = config('parser.hmac_secret');
        
        if (!$hmacSecret) {
            throw new \Exception('HMAC secret not configured');
        }
        
        $providedToken = $request->header('X-HMAC-Signature');
        
        if (!$providedToken) {
            abort(401, 'Missing HMAC signature');
        }
        
        // Вычисляем ожидаемый токен
        $body = $request->getContent();
        $expectedToken = hash_hmac('sha256', $body, $hmacSecret);
        
        // Логируем для отладки (удалить в production)
        Log::debug('HMAC Validation', [
            'provided' => substr($providedToken, 0, 16) . '...',
            'expected' => substr($expectedToken, 0, 16) . '...',
            'body_length' => strlen($body),
            'secret_length' => strlen($hmacSecret),
        ]);
        
        if (!hash_equals($expectedToken, $providedToken)) {
            abort(401, 'Invalid HMAC signature');
        }
    }
}
