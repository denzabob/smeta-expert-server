<?php

namespace App\Http\Controllers\Api\Parser;

use App\Http\Controllers\Controller;
use App\Models\SupplierUrl;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

/**
 * Контроллер очереди URL для парсинга.
 * 
 * ЭТАП 2: Механизм "claim batch" для выдачи URL воркерам.
 */
class UrlQueueController extends Controller
{
    /**
     * GET /api/parser/urls
     * 
     * Получить список URL в очереди (для веб-интерфейса).
     */
    public function index(Request $request): JsonResponse
    {
        $supplierCode = $request->query('supplier_code');
        $status = $request->query('status');
        $materialType = $request->query('material_type');
        $perPage = min((int) $request->query('per_page', 50), 100);

        $query = SupplierUrl::query()
            ->select([
                'id', 'url', 'supplier_id', 'supplier_name', 'material_type',
                'status', 'attempts', 'locked_by', 'locked_at',
                'last_attempt_at', 'last_parsed_at', 'next_retry_at',
                'last_error_code', 'last_error_message',
                'created_at', 'updated_at'
            ])
            ->orderByRaw("FIELD(status, 'processing', 'pending', 'failed', 'blocked', 'done')")
            ->orderBy('last_attempt_at', 'desc');

        if ($supplierCode) {
            $query->forSupplier($supplierCode);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($materialType) {
            $query->forMaterialType($materialType);
        }

        $urls = $query->paginate($perPage);

        return response()->json([
            'data' => $urls->items(),
            'meta' => [
                'current_page' => $urls->currentPage(),
                'last_page' => $urls->lastPage(),
                'per_page' => $urls->perPage(),
                'total' => $urls->total(),
            ],
        ]);
    }

    /**
     * POST /api/parser/urls/reset-failed
     * 
     * Сбросить failed URL обратно в pending.
     */
    public function resetFailed(Request $request): JsonResponse
    {
        $supplierCode = $request->input('supplier_code');

        $query = SupplierUrl::where('status', SupplierUrl::STATUS_FAILED)
            ->where(function ($q) {
                $q->whereNull('next_retry_at')
                  ->orWhere('next_retry_at', '<=', now());
            });

        if ($supplierCode) {
            $query->forSupplier($supplierCode);
        }

        $count = $query->update([
            'status' => SupplierUrl::STATUS_PENDING,
            'attempts' => 0,
            'last_error_code' => null,
            'last_error_message' => null,
            'next_retry_at' => null,
        ]);

        Log::info('Reset failed URLs to pending', [
            'supplier' => $supplierCode,
            'count' => $count,
        ]);

        return response()->json([
            'success' => true,
            'reset_count' => $count,
        ]);
    }

    /**
     * POST /api/parser/urls/retry-ready
     *
     * Перевести failed с next_retry_at <= now обратно в pending.
     */
    public function retryReady(Request $request): JsonResponse
    {
        $supplierCode = $request->input('supplier_code');

        $query = SupplierUrl::where('status', SupplierUrl::STATUS_FAILED)
            ->where(function ($q) {
                $q->whereNull('next_retry_at')
                  ->orWhere('next_retry_at', '<=', now());
            })
            ->where('attempts', '<', SupplierUrl::MAX_ATTEMPTS);

        if ($supplierCode) {
            $query->forSupplier($supplierCode);
        }

        $count = $query->update([
            'status' => SupplierUrl::STATUS_PENDING,
            'locked_by' => null,
            'locked_at' => null,
        ]);

        Log::info('Retry-ready failed URLs set to pending', [
            'supplier' => $supplierCode,
            'count' => $count,
        ]);

        return response()->json([
            'success' => true,
            'reset_count' => $count,
        ]);
    }

    /**
     * POST /api/parser/urls/full-scan-reset
     *
     * Полный перескан: перевести done/failed/processing(stale) в pending.
     * Сбросить все блокировки, attempts=0, очистить error.
     * 
     * ВАЖНО: вызывается ПОСЛЕ collect, поэтому все URL уже в таблице.
     */
    public function fullScanReset(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'supplier_name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $supplierName = $request->input('supplier_name');

        // Подсчёт по статусам ДО reset
        $baseQuery = SupplierUrl::forSupplier($supplierName)->where('is_valid', true);
        $countDone = (clone $baseQuery)->where('status', SupplierUrl::STATUS_DONE)->count();
        $countFailed = (clone $baseQuery)->where('status', SupplierUrl::STATUS_FAILED)->count();
        $countPending = (clone $baseQuery)->where('status', SupplierUrl::STATUS_PENDING)->count();
        $countBlocked = (clone $baseQuery)->where('status', SupplierUrl::STATUS_BLOCKED)->count();
        
        // Stale processing: locked более 30 минут назад
        $staleCutoff = Carbon::now()->subMinutes(SupplierUrl::PROCESSING_TTL_MINUTES);
        $countProcessing = (clone $baseQuery)
            ->where('status', SupplierUrl::STATUS_PROCESSING)
            ->where(function ($q) use ($staleCutoff) {
                $q->whereNull('locked_at')
                  ->orWhere('locked_at', '<', $staleCutoff);
            })
            ->count();

        // Сброс: done → pending, failed → pending, blocked → pending, processing(stale) → pending
        $resetCount = SupplierUrl::forSupplier($supplierName)
            ->where('is_valid', true)
            ->where(function ($q) use ($staleCutoff) {
                // done
                $q->where('status', SupplierUrl::STATUS_DONE)
                  // failed  
                  ->orWhere('status', SupplierUrl::STATUS_FAILED)
                  // pending (уже pending, но возможно с lock — сбрасываем lock)
                  ->orWhere('status', SupplierUrl::STATUS_PENDING)
                  // blocked
                  ->orWhere('status', SupplierUrl::STATUS_BLOCKED)
                  // processing с stale lock
                  ->orWhere(function ($q2) use ($staleCutoff) {
                      $q2->where('status', SupplierUrl::STATUS_PROCESSING)
                         ->where(function ($q3) use ($staleCutoff) {
                             $q3->whereNull('locked_at')
                                ->orWhere('locked_at', '<', $staleCutoff);
                         });
                  });
            })
            ->update([
                'status' => SupplierUrl::STATUS_PENDING,
                'locked_by' => null,
                'locked_at' => null,
                'next_retry_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
                'attempts' => 0,
            ]);

        // Подсчёт ПОСЛЕ reset
        $pendingAfter = SupplierUrl::forSupplier($supplierName)
            ->where('is_valid', true)
            ->where('status', SupplierUrl::STATUS_PENDING)
            ->count();

        Log::info('Full scan reset applied', [
            'supplier' => $supplierName,
            'reset_count' => $resetCount,
            'before' => [
                'done' => $countDone,
                'failed' => $countFailed,
                'pending' => $countPending,
                'processing_stale' => $countProcessing,
                'blocked' => $countBlocked,
            ],
            'after' => [
                'pending' => $pendingAfter,
            ],
        ]);

        return response()->json([
            'success' => true,
            'reset_count' => $resetCount,
            'before' => [
                'done' => $countDone,
                'failed' => $countFailed,
                'pending' => $countPending,
                'processing_stale' => $countProcessing,
                'blocked' => $countBlocked,
            ],
            'after' => [
                'pending' => $pendingAfter,
            ],
            'supplier' => $supplierName,
        ]);
    }

    /**
     * POST /api/parser/urls/claim
     * 
     * Выдать воркеру пачку URL для парсинга, атомарно заблокировав их.
     */
    public function claim(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'supplier_name' => 'required|string|max:255',
            'material_type' => 'nullable|string|max:255',
            'batch_size' => 'required|integer|min:1|max:100',
            'worker_id' => 'required|string|max:64',
            'reparse_days' => 'nullable|integer|min:1|max:30',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $supplierName = $request->input('supplier_name');
        $materialType = $request->input('material_type');
        $batchSize = $request->input('batch_size');
        $workerId = $request->input('worker_id');
        $reparseDays = $request->input('reparse_days', SupplierUrl::REPARSE_INTERVAL_DAYS);

        try {
            // Сначала сбрасываем зависшие processing
            $this->resetStaleProcessing();

            $claimedUrls = [];

            DB::transaction(function () use (
                $supplierName, $materialType, $batchSize, $workerId, $reparseDays, &$claimedUrls
            ) {
                // Строим запрос на выборку кандидатов
                // claimable() возвращает только pending + unlocked
                $query = SupplierUrl::forSupplier($supplierName)
                    ->claimable($reparseDays)
                    ->orderBy('created_at', 'asc')  // FIFO: старые URL первыми
                    ->limit($batchSize)
                    ->lockForUpdate(); // блокируем строки

                if ($materialType) {
                    $query->forMaterialType($materialType);
                }

                $urls = $query->get();

                if ($urls->isEmpty()) {
                    return;
                }

                // Обновляем статус на processing
                $ids = $urls->pluck('id')->toArray();
                $now = Carbon::now();

                SupplierUrl::whereIn('id', $ids)->update([
                    'status' => SupplierUrl::STATUS_PROCESSING,
                    'locked_by' => $workerId,
                    'locked_at' => $now,
                    'last_attempt_at' => $now,
                ]);

                // Формируем ответ
                $claimedUrls = $urls->map(function ($url) {
                    return [
                        'supplier_url_id' => $url->id,
                        'url' => $url->url,
                        'supplier_name' => $url->supplier_name,
                        'material_type' => $url->material_type,
                    ];
                })->toArray();
            });

            Log::info('URLs claimed for processing', [
                'supplier' => $supplierName,
                'worker_id' => $workerId,
                'claimed_count' => count($claimedUrls),
            ]);

            if (count($claimedUrls) === 0) {
                $diagnostics = $this->getClaimDiagnostics($supplierName, $materialType, $reparseDays);
                Log::info('Claim diagnostics (empty batch)', $diagnostics + [
                    'supplier' => $supplierName,
                    'worker_id' => $workerId,
                ]);
            }

            return response()->json([
                'success' => true,
                'urls' => $claimedUrls,
                'count' => count($claimedUrls),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to claim URLs', [
                'supplier' => $supplierName,
                'worker_id' => $workerId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to claim URLs',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/parser/urls/report
     * 
     * Принять результат обработки каждого URL.
     */
    public function report(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'results' => 'required|array|min:1',
            'results.*.supplier_url_id' => 'required|integer|exists:supplier_urls,id',
            'results.*.status' => 'required|in:done,failed,blocked',
            'results.*.error_code' => 'nullable|string|max:50',
            'results.*.error_message' => 'nullable|string|max:2000',
            'results.*.parsed_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $results = $request->input('results');
        $processed = ['done' => 0, 'failed' => 0, 'blocked' => 0, 'errors' => 0];

        foreach ($results as $result) {
            try {
                $supplierUrl = SupplierUrl::find($result['supplier_url_id']);
                
                if (!$supplierUrl) {
                    $processed['errors']++;
                    continue;
                }

                $parsedAt = isset($result['parsed_at']) 
                    ? Carbon::parse($result['parsed_at']) 
                    : Carbon::now();

                switch ($result['status']) {
                    case 'done':
                        $supplierUrl->markAsDone($parsedAt);
                        $processed['done']++;
                        break;

                    case 'failed':
                        $errorCode = $result['error_code'] ?? SupplierUrl::ERROR_UNKNOWN;
                        $errorMessage = $result['error_message'] ?? null;
                        $supplierUrl->markAsFailed($errorCode, $errorMessage);
                        $processed['failed']++;
                        break;

                    case 'blocked':
                        $errorCode = $result['error_code'] ?? SupplierUrl::ERROR_HTTP_403;
                        $errorMessage = $result['error_message'] ?? null;
                        $supplierUrl->markAsBlocked($errorCode, $errorMessage);
                        $processed['blocked']++;
                        break;
                }

            } catch (\Exception $e) {
                Log::error('Failed to process URL report', [
                    'supplier_url_id' => $result['supplier_url_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
                $processed['errors']++;
            }
        }

        Log::info('URL processing report received', $processed);

        return response()->json([
            'success' => true,
            'processed' => $processed,
        ]);
    }

    /**
     * GET /api/parser/urls/stats
     * 
     * Получить статистику очереди.
     */
    public function stats(Request $request): JsonResponse
    {
        $supplierName = $request->query('supplier_name');

        $query = SupplierUrl::query();
        
        if ($supplierName) {
            $query->forSupplier($supplierName);
        }

        $stats = [
            'total' => (clone $query)->count(),
            'pending' => (clone $query)->pending()->count(),
            'processing' => (clone $query)->processing()->count(),
            'done' => (clone $query)->done()->count(),
            'failed' => (clone $query)->failed()->count(),
            'blocked' => (clone $query)->blocked()->count(),
            'valid' => (clone $query)->valid()->count(),
            'invalid' => (clone $query)->invalid()->count(),
        ];

        // По поставщикам
        $bySupplier = SupplierUrl::selectRaw('
                supplier_name,
                status,
                COUNT(*) as count
            ')
            ->groupBy('supplier_name', 'status')
            ->get()
            ->groupBy('supplier_name')
            ->map(function ($items) {
                return $items->pluck('count', 'status');
            });

        return response()->json([
            'success' => true,
            'stats' => $stats,
            'by_supplier' => $bySupplier,
        ]);
    }

    /**
     * GET /api/parser/urls/diagnostics
     *
     * Вернуть диагностику claimable причин для supplier.
     */
    public function diagnostics(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'supplier_name' => 'required|string|max:255',
            'material_type' => 'nullable|string|max:255',
            'reparse_days' => 'nullable|integer|min:1|max:30',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $supplierName = $request->input('supplier_name');
        $materialType = $request->input('material_type');
        $reparseDays = $request->input('reparse_days', SupplierUrl::REPARSE_INTERVAL_DAYS);

        $diagnostics = $this->getClaimDiagnostics($supplierName, $materialType, $reparseDays);

        return response()->json([
            'success' => true,
            'diagnostics' => $diagnostics,
        ]);
    }

    /**
     * POST /api/parser/urls/reset-stale
     * 
     * Вручную сбросить зависшие processing.
     */
    public function resetStale(Request $request): JsonResponse
    {
        $count = $this->resetStaleProcessing();

        return response()->json([
            'success' => true,
            'reset_count' => $count,
        ]);
    }

    /**
     * POST /api/parser/urls/release
     * 
     * Освободить processing URL для воркера без увеличения attempts.
     */
    public function release(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'worker_id' => 'required|string|max:64',
            'supplier_name' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $workerId = $request->input('worker_id');
        $supplierName = $request->input('supplier_name');

        $query = SupplierUrl::where('status', SupplierUrl::STATUS_PROCESSING)
            ->where('locked_by', $workerId);

        if ($supplierName) {
            $query->forSupplier($supplierName);
        }

        $count = $query->update([
            'status' => SupplierUrl::STATUS_PENDING,
            'locked_by' => null,
            'locked_at' => null,
        ]);

        Log::warning('Released processing URLs without attempts update', [
            'worker_id' => $workerId,
            'supplier' => $supplierName,
            'count' => $count,
        ]);

        return response()->json([
            'success' => true,
            'released' => $count,
        ]);
    }

    /**
     * Сбросить зависшие processing записи.
     */
    protected function resetStaleProcessing(): int
    {
        $staleUrls = SupplierUrl::staleProcessing()->get();
        
        foreach ($staleUrls as $url) {
            $url->resetStaleProcessing();
        }

        if ($staleUrls->count() > 0) {
            Log::warning('Reset stale processing URLs', [
                'count' => $staleUrls->count(),
            ]);
        }

        return $staleUrls->count();
    }

    /**
     * Диагностика причин empty claim.
     */
    protected function getClaimDiagnostics(string $supplierName, ?string $materialType, int $reparseDays): array
    {
        $now = Carbon::now();
        $ttlMinutes = SupplierUrl::PROCESSING_TTL_MINUTES;

        $base = SupplierUrl::forSupplier($supplierName)->valid();
        if ($materialType) {
            $base->forMaterialType($materialType);
        }

        $pending = (clone $base)->pending()->count();
        $failedReady = (clone $base)->failed()
            ->where(function ($q) use ($now) {
                $q->whereNull('next_retry_at')
                  ->orWhere('next_retry_at', '<=', $now);
            })
            ->where('attempts', '<', SupplierUrl::MAX_ATTEMPTS)
            ->count();
        $failedNotReady = (clone $base)->failed()
            ->where('next_retry_at', '>', $now)
            ->count();
        $processingLocked = (clone $base)->processing()
            ->where('locked_at', '>=', $now->copy()->subMinutes($ttlMinutes))
            ->count();
        $processingStale = (clone $base)->processing()
            ->where('locked_at', '<', $now->copy()->subMinutes($ttlMinutes))
            ->count();
        $done = (clone $base)->done()->count();

        return [
            'pending_count' => $pending,
            'failed_ready_count' => $failedReady,
            'failed_not_ready_count' => $failedNotReady,
            'processing_locked_count' => $processingLocked,
            'processing_stale_count' => $processingStale,
            'done_count' => $done,
        ];
    }
}
