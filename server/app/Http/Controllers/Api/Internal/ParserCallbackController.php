<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\ParsingSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Models\SupplierUrl;

class ParserCallbackController extends Controller
{
    /**
     * Handle callback from Python parser.
     *
     * POST /api/internal/parser/callback
     *
     * Request format:
     * {
     *   "session_id": 123,
     *   "token": "secret_hash",
    *   "timestamp": 1735399845,
     *   "type": "log|progress|finish",
     *   "payload": { ... }
     * }
     */
    public function handle(Request $request): JsonResponse
    {
        try {
            $callbackType = $request->input('type');
            if ($callbackType !== 'progress') {
                Log::info("Parser callback received", [
                    'type' => $callbackType,
                    'session_id' => $request->input('session_id'),
                ]);
            }
            
            // Валидируем входные данные
            $validated = Validator::validate($request->all(), [
                'session_id' => 'required|integer|exists:parsing_sessions,id',
                'token' => 'required|string',
                'type' => 'required|in:log,progress,finish,total_urls,mark_url_failed,phase_started,phase_progress,phase_finished',
                'timestamp' => 'required|integer',
                'event_id' => 'required|string',
                'payload' => 'required|array',
            ]);

            // Получаем сессию
            $session = ParsingSession::findOrFail($validated['session_id']);

            // Проверяем токен безопасности
            $bearer = $request->bearerToken();
            $candidate = $validated['token'] ?: $bearer;

            if (!$this->validateToken($session->id, $candidate)) {
                Log::warning(
                    "Invalid token for parser callback",
                    ['session_id' => $session->id]
                );

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid token',
                ], 401);
            }

            // Дедупликация event_id
            $eventKey = "parser:cb:{$session->id}:{$validated['event_id']}";
            if (Cache::has($eventKey)) {
                return response()->json(['success' => true]);
            }
            Cache::put($eventKey, true, now()->addHours(1));

            // Обрабатываем различные типы callback'ов
            $response = match ($validated['type']) {
                'log' => $this->handleLog($session, $validated['payload']),
                'progress' => $this->handleProgress($session, $validated['payload']),
                'total_urls' => $this->handleTotalUrls($session, $validated['payload']),
                'mark_url_failed' => $this->handleMarkUrlFailed($session, $validated['payload']),
                'finish' => $this->handleFinish($session, $validated['payload']),
                // New phase-based callbacks
                'phase_started' => $this->handlePhaseStarted($session, $validated['payload']),
                'phase_progress' => $this->handlePhaseProgress($session, $validated['payload']),
                'phase_finished' => $this->handlePhaseFinished($session, $validated['payload']),
                default => ['success' => false]
            };

            // Возвращаем команду управления если сессия в статусе "canceling"
            $command = null;
            if ($session->refresh()->status === 'canceling') {
                $command = 'stop';
                Log::info("Sending stop command to parser for session {$session->id}");
            }

            return response()->json([
                'success' => $response['success'] ?? true,
                'command' => $command,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning("Validation error in parser callback", [
                'errors' => $e->errors(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error("Error handling parser callback", [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error',
            ], 500);
        }
    }

    /**
     * Handle log events from parser.
     *
     * Payload format (batch of logs):
     * [
     *   { "level": "info", "message": "...", "details": {...} },
     *   { "level": "warning", "message": "...", "details": {...} },
     *   ...
     * ]
     */
    protected function handleLog(ParsingSession $session, array $payload): array
    {
        try {
            // Обрабатываем батч логов
            if (is_array($payload) && !empty($payload)) {
                // Если это батч (старый формат)
                if (isset($payload[0]['level']) && isset($payload[0]['message'])) {
                    foreach ($payload as $log) {
                        $session->addLog(
                            $log['level'] ?? 'info',
                            $log['message'] ?? '',
                            $log['details'] ?? null
                        );
                    }
                } else {
                    // Если это одиночный лог
                    $session->addLog(
                        $payload['level'] ?? 'info',
                        $payload['message'] ?? '',
                        $payload['details'] ?? null
                    );
                }
            }

            Log::info("Stored logs for session {$session->id}");

            return ['success' => true];
        } catch (\Exception $e) {
            Log::error("Error storing logs for session {$session->id}", [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return ['success' => false];
        }
    }

    /**
     * Handle total_urls update from parser.
     *
     * Payload format:
     * {
     *   "total_urls": 645
     * }
     */
    protected function handleTotalUrls(ParsingSession $session, array $payload): array
    {
        try {
            $totalUrls = $payload['total'] ?? ($payload['total_urls'] ?? 0);

            $currentTotal = (int) ($session->total_urls ?? 0);
            if ($totalUrls > $currentTotal) {
                $session->update([
                    'total_urls' => $totalUrls,
                ]);

                Log::info("Updated total_urls for session {$session->id}: {$totalUrls}", [
                    'previous' => $currentTotal,
                ]);
            } else {
                Log::info("Ignored total_urls update for session {$session->id}", [
                    'incoming' => $totalUrls,
                    'current' => $currentTotal,
                ]);
            }

            return ['success' => true];
        } catch (\Exception $e) {
            Log::error("Error updating total_urls for session {$session->id}", [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return ['success' => false];
        }
    }

    /**
     * Handle progress updates from parser.
     *
     * Payload format:
     * {
     *   "processed": 42,
     *   "total": 100,
     *   "percent": 42.00
     * }
     */
    protected function handleProgress(ParsingSession $session, array $payload): array
    {
        try {
            $processed = $payload['processed'] ?? 0;
            $total = $payload['total'] ?? 0;
            $currentProcessed = (int) ($session->pages_processed ?? 0);
            $currentTotal = (int) ($session->total_urls ?? 0);
            $shouldUpdate = $processed > $currentProcessed || ($total > 0 && $total > $currentTotal);

            if (!$shouldUpdate) {
                return ['success' => true];
            }

            // Ensure session is marked as running when we receive progress
            if ($session->status === 'pending') {
                $session->markAsRunning();
            }

            // В БД поле называется pages_processed
            $session->update([
                'pages_processed' => $processed,
                'last_heartbeat' => now(),
            ]);

            Log::info("Updated progress for session {$session->id}: {$processed}/{$total}");

            return ['success' => true];
        } catch (\Exception $e) {
            Log::error("Error updating progress for session {$session->id}", [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return ['success' => false];
        }
    }

    /**
     * Handle finish events from parser.
     *
     * Payload format:
     * {
     *   "status": "success|partial|failed",
     *   "summary": {
     *     "total_processed": 50,
     *     "total_urls": 100,
     *     "successful": 48,
     *     "errors": 2,
     *     "screenshots_taken": 10
     *   }
     * }
     */
    protected function handleFinish(ParsingSession $session, array $payload): array
    {
        try {
            $rawStatus = $payload['status'] ?? ($payload['result'] ?? 'success');
            $status = $rawStatus;
            $summary = $payload['summary'] ?? [];

            $pendingCount = SupplierUrl::forSupplier($session->supplier_name)
                ->claimable()
                ->count();

            if ($pendingCount > 0) {
                $updateData = [
                    'last_heartbeat_at' => now(),
                ];

                if (!empty($summary)) {
                    $updateData['parse_stats_json'] = $summary;
                    $updateData['pages_processed'] = $summary['total_processed'] ?? $session->pages_processed;
                    $updateData['items_updated'] = $summary['successful'] ?? $session->items_updated;
                    $updateData['errors_count'] = $summary['errors'] ?? $session->errors_count;
                }

                $session->update($updateData);

                Log::info("Finish deferred for session {$session->id} (pending > 0)", [
                    'pending_count' => $pendingCount,
                    'provided_status' => $rawStatus,
                ]);

                return ['success' => true, 'deferred' => true, 'pending_count' => $pendingCount];
            }
            
            // Whitelist validation for result_status
            $statusMap = [
                'completed' => 'success',
                'ok' => 'success',
            ];
            if (isset($statusMap[$status])) {
                $status = $statusMap[$status];
            }

            $validStatuses = ['success', 'partial', 'failed'];
            if (!in_array($status, $validStatuses)) {
                Log::warning("Invalid finish status '{$status}', defaulting to 'failed'", [
                    'session_id' => $session->id,
                    'provided_status' => $rawStatus,
                ]);
                $status = 'failed';
            }

            // Build update data
            $updateData = [
                'finished_at' => now(),
                'result_status' => $status,
                'parse_finished_at' => now(),
            ];
            
            // Store parse stats as JSON
            if (!empty($summary)) {
                $updateData['parse_stats_json'] = $summary;
                $updateData['pages_processed'] = $summary['total_processed'] ?? $session->pages_processed;
                $updateData['items_updated'] = $summary['successful'] ?? $session->items_updated;
                $updateData['errors_count'] = $summary['errors'] ?? $session->errors_count;
            }

            // Update lifecycle_status based on result
            if ($status === 'success') {
                $updateData['lifecycle_status'] = ParsingSession::LIFECYCLE_FINISHED_SUCCESS;
                $updateData['status'] = ParsingSession::DB_STATUS_COMPLETED;
            } else {
                $updateData['lifecycle_status'] = ParsingSession::LIFECYCLE_FINISHED_FAILED;
                $updateData['status'] = ParsingSession::DB_STATUS_FAILED;
                if ($status === 'partial') {
                    $updateData['stop_reason'] = 'PARTIAL_COMPLETION';
                }
            }

            // Perform update
            $session->update($updateData);

            Log::info("Finished parsing for session {$session->id}", [
                'result_status' => $status,
                'lifecycle_status' => $updateData['lifecycle_status'],
                'summary' => $summary,
            ]);

            return ['success' => true];
        } catch (\Exception $e) {
            Log::error("Error finishing session {$session->id}", [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Handle marking a URL as failed/invalid.
     *
     * Payload format:
     * {
     *   "url": "https://...",
     *   "error": "Not a product page",
     *   "error_code": "INVALID_PAGE"
     * }
     */
    protected function handleMarkUrlFailed(ParsingSession $session, array $payload): array
    {
        try {
            $url = $payload['url'] ?? null;
            $error = $payload['error'] ?? 'Unknown error';
            $errorCode = $payload['error_code'] ?? 'PARSE_ERROR';

            if (!$url) {
                return ['success' => false, 'message' => 'URL is required'];
            }

            $supplierUrl = \App\Models\SupplierUrl::where('url', $url)
                ->where('supplier_name', $session->supplier_name)
                ->first();

            if (!$supplierUrl) {
                return ['success' => false, 'message' => 'URL not found'];
            }

            if (in_array($errorCode, ['HTTP_403', 'HTTP_404'], true)) {
                $supplierUrl->markAsBlocked($errorCode, $error);
            } else {
                $supplierUrl->markAsFailed($errorCode, $error);
            }

            if ($supplierUrl->exists) {
                Log::info("Marked URL as failed", [
                    'url' => $url,
                    'error_code' => $errorCode,
                    'session_id' => $session->id,
                ]);
            }

            return ['success' => true, 'affected' => 1];
        } catch (\Exception $e) {
            Log::error("Error marking URL as failed", [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return ['success' => false];
        }
    }

    /**
     * Validate callback token.
     *
     * Generates HMAC of session_id using parser.callback_token as key.
     */
    protected function validateToken(int $sessionId, string $token): bool
    {
        $secret = config('parser.callback_token', '');

        if (hash_equals($secret, $token)) {
            return true;
        }

        // Backward compatibility: accept legacy HMAC token
        $expectedToken = hash_hmac(
            'sha256',
            (string) $sessionId,
            $secret
        );

        return hash_equals($expectedToken, $token);
    }

    // ==================== PHASE-BASED CALLBACKS ====================

    /**
     * Handle phase_started callback.
     * 
     * Payload format:
     * {
     *   "phase": "collect|reset|parse",
     *   "started_at": "2026-01-21T12:00:00Z"
     * }
     */
    protected function handlePhaseStarted(ParsingSession $session, array $payload): array
    {
        try {
            $phase = $payload['phase'] ?? 'unknown';
            
            Log::info("[CALLBACK:{$session->id}] Phase started", ['phase' => $phase]);
            
            $updateData = ['last_heartbeat_at' => now()];
            
            switch ($phase) {
                case 'collect':
                    $updateData['collect_started_at'] = now();
                    \App\Models\ParsingLog::create([
                        'session_id' => $session->id,
                        'url' => '',
                        'level' => 'info',
                        'message' => 'Collect phase started',
                    ]);
                    break;
                case 'reset':
                    $updateData['reset_started_at'] = now();
                    \App\Models\ParsingLog::create([
                        'session_id' => $session->id,
                        'url' => '',
                        'level' => 'info',
                        'message' => 'Reset phase started',
                    ]);
                    break;
                case 'parse':
                    $updateData['parse_started_at'] = now();
                    \App\Models\ParsingLog::create([
                        'session_id' => $session->id,
                        'url' => '',
                        'level' => 'info',
                        'message' => 'Parse phase started',
                    ]);
                    break;
            }
            
            $session->update($updateData);
            
            return ['success' => true];
        } catch (\Exception $e) {
            Log::error("[CALLBACK:{$session->id}] Error handling phase_started", [
                'exception' => $e->getMessage(),
            ]);
            return ['success' => false];
        }
    }

    /**
     * Handle phase_progress callback.
     * 
     * Payload format:
     * {
     *   "phase": "collect|reset|parse",
     *   "processed": 100,
     *   "total": 500,
     *   "extra": { ... stats ... }
     * }
     */
    protected function handlePhaseProgress(ParsingSession $session, array $payload): array
    {
        try {
            $phase = $payload['phase'] ?? 'unknown';
            $processed = $payload['processed'] ?? 0;
            $total = $payload['total'] ?? 0;
            $extra = $payload['extra'] ?? [];
            
            Log::info("[CALLBACK:{$session->id}] Phase progress", [
                'phase' => $phase,
                'processed' => $processed,
                'total' => $total,
            ]);
            
            $updateData = [
                'last_heartbeat_at' => now(),
                'pages_processed' => $processed,
            ];
            
            // Store phase-specific stats
            if ($phase === 'collect' && !empty($extra)) {
                $updateData['collect_stats_json'] = $extra;
            } elseif ($phase === 'parse' && !empty($extra)) {
                $updateData['parse_stats_json'] = $extra;
            }
            
            if ($total > 0) {
                $currentTotal = (int) ($session->total_urls ?? 0);
                if ($total > $currentTotal) {
                    $updateData['total_urls'] = $total;
                }
            }
            
            $session->update($updateData);
            
            return ['success' => true];
        } catch (\Exception $e) {
            Log::error("[CALLBACK:{$session->id}] Error handling phase_progress", [
                'exception' => $e->getMessage(),
            ]);
            return ['success' => false];
        }
    }

    /**
     * Handle phase_finished callback.
     * 
     * Payload format:
     * {
     *   "phase": "collect|reset|parse",
     *   "result": "success|failed",
     *   "stats": {
     *     "urls_found_total": 1000,
     *     "urls_unique_total": 640,
     *     "urls_sent_total": 640,
     *     "duplicates_dropped": 360,
     *     "stop_reason": "TIME_LIMIT_REACHED"
     *   }
     * }
     */
    protected function handlePhaseFinished(ParsingSession $session, array $payload): array
    {
        try {
            $phase = $payload['phase'] ?? 'unknown';
            $result = $payload['result'] ?? 'success';
            $stats = $payload['stats'] ?? [];
            
            Log::info("[CALLBACK:{$session->id}] Phase finished", [
                'phase' => $phase,
                'result' => $result,
                'stats' => $stats,
            ]);
            
            $updateData = ['last_heartbeat_at' => now()];
            
            switch ($phase) {
                case 'collect':
                    $updateData['collect_finished_at'] = now();
                    $updateData['collect_stats_json'] = $stats;
                    $collectSent = $stats['urls_sent_total'] ?? null;
                    $collectUnique = $stats['urls_unique_total'] ?? null;
                    if ($collectSent !== null) {
                        $updateData['collect_urls_count'] = $collectSent;
                        $updateData['total_urls'] = $collectSent;
                    } elseif ($collectUnique !== null) {
                        $updateData['collect_urls_count'] = $collectUnique;
                        $updateData['total_urls'] = $collectUnique;
                    }
                    if (isset($stats['stop_reason'])) {
                        $updateData['stop_reason'] = $stats['stop_reason'];
                    }

                    \App\Models\ParsingLog::create([
                        'session_id' => $session->id,
                        'url' => '',
                        'level' => 'info',
                        'message' => 'Collect phase finished',
                    ]);

                    if ($session->full_scan_run_id === 'collect_only') {
                        $updateData['status'] = ParsingSession::DB_STATUS_COMPLETED;
                        $updateData['lifecycle_status'] = ParsingSession::LIFECYCLE_FINISHED_SUCCESS;
                        $updateData['finished_at'] = now();
                    } elseif ($session->getLifecycleStatus() === ParsingSession::LIFECYCLE_COLLECTING) {
                        $updateData['lifecycle_status'] = ParsingSession::LIFECYCLE_COLLECTED;
                    }
                    break;
                    
                case 'reset':
                    $updateData['reset_finished_at'] = now();
                    break;
                    
                case 'parse':
                    $updateData['parse_finished_at'] = now();
                    $updateData['parse_stats_json'] = $stats;
                    if (isset($stats['successful'])) {
                        $updateData['items_updated'] = $stats['successful'];
                    }
                    if (isset($stats['errors'])) {
                        $updateData['errors_count'] = $stats['errors'];
                    }
                    break;
            }
            
            $session->update($updateData);
            
            return ['success' => true, 'phase' => $phase, 'result' => $result];
        } catch (\Exception $e) {
            Log::error("[CALLBACK:{$session->id}] Error handling phase_finished", [
                'exception' => $e->getMessage(),
            ]);
            return ['success' => false];
        }
    }
}
