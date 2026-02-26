<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ParsingSessionResource;
use App\Jobs\RunParserJob;
use App\Models\ParsingSession;
use App\Models\ParsingLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ParsingController extends Controller
{
    /**
     * Create a new parsing session and dispatch the job.
     *
     * POST /api/parsing/sessions
     *
     * Request format:
     * {
     *   "supplier": "skm_mebel",
     *   "config": { ... }  // optional
     *   "max_collect_pages": 100,  // optional - limit for collect phase
     *   "max_collect_urls": 5000,  // optional - limit for collect phase
     *   "max_collect_time_seconds": 600  // optional - timeout for collect phase
     * }
     * 
     * ANTI-LOOP: This is the ONLY entry point for starting parsing.
     * No auto-start from containers, workers, or cron.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Валидируем входные данные
            $validated = Validator::validate($request->all(), [
                'supplier' => 'required|string',
                'config' => 'nullable|array',
                'max_collect_pages' => 'nullable|integer|min:1|max:10000',
                'max_collect_urls' => 'nullable|integer|min:1|max:100000',
                'max_collect_time_seconds' => 'nullable|integer|min:60|max:7200',
            ]);

            // Check for existing active session for this supplier
            $existingSession = ParsingSession::where('supplier_name', $validated['supplier'])
                ->whereNotIn('lifecycle_status', ParsingSession::TERMINAL_STATUSES)
                ->first();
            
            if ($existingSession) {
                Log::warning("Blocked: Active session exists for supplier", [
                    'supplier' => $validated['supplier'],
                    'existing_session_id' => $existingSession->id,
                    'lifecycle_status' => $existingSession->getLifecycleStatus(),
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => "Active session already exists for supplier '{$validated['supplier']}' (session_id={$existingSession->id}, status={$existingSession->getLifecycleStatus()}). Wait for completion or abort it first.",
                    'existing_session_id' => $existingSession->id,
                ], 409);
            }

            // Создаём запись сессии в БД с статусом "created"
            $session = ParsingSession::create([
                'supplier_name' => $validated['supplier'],
                'status' => 'pending',
                'lifecycle_status' => ParsingSession::STATUS_CREATED,
                'max_collect_pages' => $validated['max_collect_pages'] ?? null,
                'max_collect_urls' => $validated['max_collect_urls'] ?? null,
                'max_collect_time_seconds' => $validated['max_collect_time_seconds'] ?? null,
            ]);

            Log::info("Created parsing session {$session->id} for supplier {$validated['supplier']}", [
                'session_run_id' => $session->session_run_id,
                'lifecycle_status' => $session->lifecycle_status,
            ]);

            // GUARD: Can we dispatch?
            if (!$session->canDispatchJob()) {
                Log::error("BLOCKED: Cannot dispatch job for session", [
                    'session_id' => $session->id,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Session cannot be dispatched',
                ], 500);
            }
            
            // Mark as dispatched BEFORE actual dispatch to prevent race conditions
            $session->markJobDispatched();

            // Отправляем задачу в очередь
            dispatch(new RunParserJob(
                $session,
                $validated['supplier'],
                $validated['config'] ?? []
            ));

            Log::info("Dispatched RunParserJob for session {$session->id}");

            // Возвращаем ID сессии пользователю
            return response()->json([
                'success' => true,
                'session_id' => $session->id,
                'session_run_id' => $session->session_run_id,
                'session' => new ParsingSessionResource($session->load('logs')),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning("Validation error in ParsingController@store", [
                'errors' => $e->errors(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error("Error creating parsing session", [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create parsing session',
            ], 500);
        }
    }

    /**
     * Get parsing session details.
     *
     * GET /api/parsing/sessions/{id}
     */
    public function show(ParsingSession $session): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => new ParsingSessionResource($session->load('logs')),
            ]);
        } catch (\Exception $e) {
            Log::error("Error retrieving parsing session {$session->id}", [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve session',
            ], 500);
        }
    }

    /**
     * Update parsing session (e.g., cancel it).
     *
     * PATCH /api/parsing/sessions/{id}
     *
     * Request format:
     * {
     *   "status": "canceling"
     * }
     */
    public function update(Request $request, ParsingSession $session): JsonResponse
    {
        try {
            $validated = Validator::validate($request->all(), [
                'status' => 'nullable|in:canceling',
            ]);

            if ($request->has('status')) {
                match ($request->input('status')) {
                    'canceling' => $session->markAsCanceling(),
                    default => null,
                };

                Log::info("Updated session {$session->id} status to {$request->input('status')}");
            }

            return response()->json([
                'success' => true,
                'data' => $session->refresh(),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error("Error updating parsing session {$session->id}", [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update session',
            ], 500);
        }
    }

    /**
     * Get logs for a parsing session.
     *
     * GET /api/parsing/sessions/{id}/logs
     *
     * Query parameters:
     * - level: Filter by log level (info, warning, error)
     * - limit: Number of logs to return (default: 100)
     * - offset: Pagination offset (default: 0)
     */
    public function logs(Request $request, ParsingSession $session): JsonResponse
    {
        try {
            $query = $session->logs();

            // Фильтруем по уровню если указан
            if ($request->has('level')) {
                $query->where('level', $request->input('level'));
            }

            // Получаем логи с пагинацией
            $limit = min($request->input('limit', 100), 1000);
            $offset = $request->input('offset', 0);

            $logs = $query
                ->orderBy('created_at', 'asc')
                ->skip($offset)
                ->take($limit)
                ->get();

            $total = $query->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'logs' => $logs,
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $limit) < $total,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error("Error retrieving logs for session {$session->id}", [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve logs',
            ], 500);
        }
    }

    /**
     * List all parsing sessions.
     *
     * GET /api/parsing/sessions
     *
     * Query parameters:
     * - supplier: Filter by supplier name
     * - status: Filter by status
     * - limit: Results per page (default: 20)
     * - page: Page number (default: 1)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = ParsingSession::query();

            // Фильтруем по поставщику если указан
            if ($request->has('supplier')) {
                $query->where('supplier_name', $request->input('supplier'));
            }

            // Фильтруем по статусу если указан
            if ($request->has('status')) {
                $query->where('status', $request->input('status'));
            }

            // Сортируем по дате создания (новые сверху)
            $query->orderBy('created_at', 'desc');

            // Пагинируем
            $limit = min($request->input('limit', 20), 100);
            $sessions = $query
                ->paginate($limit, ['*'], 'page', $request->input('page', 1));

            return response()->json([
                'success' => true,
                'data' => ParsingSessionResource::collection($sessions),
            ]);
        } catch (\Exception $e) {
            Log::error("Error retrieving parsing sessions", [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve sessions',
            ], 500);
        }
    }
    
    /**
     * Stop a running parsing session (graceful shutdown)
     *
     * POST /api/parsing/sessions/{session}/stop
     */
    public function stop(Request $request, ParsingSession $session): JsonResponse
    {
        try {
            // Already terminal - cannot stop
            if ($session->isTerminal()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session is already terminal (status: ' . $session->getLifecycleStatus() . ')',
                ], 400);
            }
            
            // Abort the session
            $session->abort('user');
            
            Log::info("User aborted session {$session->id}");
            
            return response()->json([
                'success' => true,
                'message' => 'Session aborted',
                'data' => new ParsingSessionResource($session->refresh()),
            ]);
        } catch (\Exception $e) {
            Log::error("Error stopping session {$session->id}", [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to stop session: ' . $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Get session state for parser (used by Python to check lifecycle status).
     *
     * GET /api/parsing/sessions/{session}/state
     */
    public function getState(ParsingSession $session): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $session->exportStateForParser(),
            ]);
        } catch (\Exception $e) {
            Log::error("Error getting session state {$session->id}", [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get session state',
            ], 500);
        }
    }
    
    /**
     * Retry failed URLs from a previous failed/completed session.
     * Creates a NEW session for the retry - no in-place restarts.
     *
     * POST /api/parsing/sessions/{session}/retry-failed-urls
     */
    public function retryFailedUrls(Request $request, ParsingSession $session): JsonResponse
    {
        try {
            // Must be from a terminal session
            if (!$session->isTerminal()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Can only retry from terminal sessions (completed/failed/aborted)',
                ], 400);
            }
            
            // Check for failed URLs
            $failedCount = \App\Models\SupplierUrl::forSupplier($session->supplier_name)
                ->where('status', \App\Models\SupplierUrl::STATUS_FAILED)
                ->count();
            
            if ($failedCount === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No failed URLs to retry',
                ], 400);
            }
            
            // Check for existing active session
            $existingSession = ParsingSession::where('supplier_name', $session->supplier_name)
                ->whereNotIn('lifecycle_status', ParsingSession::TERMINAL_STATUSES)
                ->first();
            
            if ($existingSession) {
                return response()->json([
                    'success' => false,
                    'message' => "Active session exists for supplier (session_id={$existingSession->id})",
                ], 409);
            }
            
            // Create NEW session for retry (NOT modifying existing session)
            $newSession = ParsingSession::create([
                'supplier_name' => $session->supplier_name,
                'status' => 'pending',
                'lifecycle_status' => ParsingSession::STATUS_COLLECT_DONE, // Skip collect, go to parsing
                'collect_finished_at' => now(),
                'collect_urls_count' => $failedCount,
                'total_urls' => $failedCount,
            ]);
            
            // Reset failed URLs to pending for retry
            \App\Models\SupplierUrl::forSupplier($session->supplier_name)
                ->where('status', \App\Models\SupplierUrl::STATUS_FAILED)
                ->update([
                    'status' => \App\Models\SupplierUrl::STATUS_PENDING,
                    'error_code' => null,
                    'error_message' => null,
                    'claimed_at' => null,
                ]);
            
            // Dispatch job (will skip collect, go straight to parsing)
            $newSession->markJobDispatched();
            dispatch(new RunParserJob(
                $newSession,
                $session->supplier_name,
                []
            ));
            
            Log::info("Created retry session {$newSession->id} for {$failedCount} failed URLs", [
                'original_session' => $session->id,
                'supplier' => $session->supplier_name,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => "Created new session to retry {$failedCount} failed URLs",
                'original_session_id' => $session->id,
                'new_session_id' => $newSession->id,
                'failed_urls_count' => $failedCount,
                'session' => new ParsingSessionResource($newSession),
            ], 201);
            
        } catch (\Exception $e) {
            Log::error("Error retrying failed URLs for session {$session->id}", [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retry URLs: ' . $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Update total URLs for a parsing session (called by parser after URL collection).
     *
     * POST /api/parsing/update-total
     *
     * Request format (with HMAC validation):
     * {
     *   "session_id": 70,
     *   "total_urls": 150,
     *   "token": "hmac_hash",
     *   "timestamp": "2026-01-04T10:00:00Z"
     * }
     */
    public function updateTotal(Request $request): JsonResponse
    {
        try {
            // Валидируем входные данные
            $validated = Validator::validate($request->all(), [
                'session_id' => 'required|integer',
                'total_urls' => 'required|integer|min:0',
                'token' => 'required|string',
                'timestamp' => 'required|string',
            ]);

            // Получаем сессию
            $session = ParsingSession::find($validated['session_id']);
            if (!$session) {
                Log::warning("Session not found for update-total", [
                    'session_id' => $validated['session_id'],
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Session not found',
                ], 404);
            }

            // HMAC валидация токена (совпадает с валидацией на Python стороне)
            $token = config('app.parser_token', env('PARSER_TOKEN', 'parser-secret'));
            $expected_token = hash_hmac(
                'sha256',
                strval($validated['session_id']),
                $token
            );

            if (!hash_equals($expected_token, $validated['token'])) {
                Log::warning("Invalid HMAC token for update-total", [
                    'session_id' => $validated['session_id'],
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid token',
                ], 401);
            }

            // Обновляем total_urls в БД
            $session->update([
                'total_urls' => $validated['total_urls'],
            ]);

            Log::info("Updated total_urls for session {$session->id}", [
                'total_urls' => $validated['total_urls'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'total_urls updated',
                'data' => new ParsingSessionResource($session->refresh()),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning("Validation error in ParsingController@updateTotal", [
                'errors' => $e->errors(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error("Error updating total_urls", [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to update total_urls',
            ], 500);
        }
    }
}
