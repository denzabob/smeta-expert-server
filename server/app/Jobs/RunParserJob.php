<?php

namespace App\Jobs;

use App\Models\ParsingSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use App\Models\SupplierUrl;

/**
 * RunParserJob — DETERMINISTIC PARSER (ANTI-LOOP ARCHITECTURE)
 * 
 * REQUIREMENTS:
 * 1. NO auto-restart on failure/timeout - session goes to 'failed' state
 * 2. NO re-dispatch from queue retry - max 1 attempt, then fail
 * 3. collect runs EXACTLY ONCE per session_run_id
 * 4. reset runs EXACTLY ONCE per session_run_id (after collect_done)
 * 5. Crash/timeout → failed (not retry)
 * 
 * LIFECYCLE:
 * created → collecting → collect_done → parsing → completed
 *        ↘              ↘               ↘
 *         → failed       → failed        → failed
 */
class RunParserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * CRITICAL: Only 1 try - no auto-retry on failure
     * Failures go to 'failed' status, not re-queued
     */
    public $tries = 1;

    /**
     * Timeout for entire job - set high, individual phases have own limits
     */
    public $timeout = 7200; // 2 hours max for entire job

    /**
     * No backoff - we don't retry
     */
    public $backoff = 0;

    /**
     * Delete job on failure (don't keep in failed_jobs)
     */
    public $deleteWhenMissingModels = true;

    /**
     * URLs per batch.
     */
    public const BATCH_SIZE = 30;
    
    /**
     * Timeouts per phase (in seconds)
     */
    public const COLLECT_TIMEOUT = 3600;  // 1 hour for collect
    public const RESET_TIMEOUT = 120;      // 2 minutes for reset
    public const PARSE_BATCH_TIMEOUT = 900; // 15 minutes per batch

    protected ParsingSession $session;
    protected string $supplier;
    protected array $config;

    public function __construct(
        ParsingSession $session, 
        string $supplier, 
        array $config = []
    ) {
        $this->session = $session;
        $this->supplier = $supplier;
        $this->config = $config;
    }

    public function handle(): void
    {
        $sessionId = $this->session->id;
        $runId = $this->session->session_run_id;
        
        Log::info("[JOB:{$sessionId}] RunParserJob STARTED", [
            'supplier' => $this->supplier,
            'session_run_id' => $runId,
            'lifecycle_status' => $this->session->getLifecycleStatus(),
        ]);

        try {
            $this->session->refresh();
            
            // ==================== GUARD: Check terminal state ====================
            if ($this->session->isTerminal()) {
                Log::warning("[JOB:{$sessionId}] SKIP: Session is terminal", [
                    'status' => $this->session->getLifecycleStatus(),
                ]);
                return;
            }

            // ==================== PHASE 1: COLLECT (if not done) ====================
            if ($this->session->canStartCollect()) {
                Log::info("[JOB:{$sessionId}] Starting COLLECT phase");
                
                $collectSuccess = $this->runCollectPhase();
                if (!$collectSuccess) {
                    // Session already marked as failed
                    return;
                }
            } elseif ($this->session->hasCollectExecuted()) {
                Log::info("[JOB:{$sessionId}] SKIP collect: already executed", [
                    'lifecycle_status' => $this->session->getLifecycleStatus(),
                ]);
            } else {
                Log::error("[JOB:{$sessionId}] Invalid state for collect", [
                    'lifecycle_status' => $this->session->getLifecycleStatus(),
                ]);
                $this->session->fail('INVALID_STATE_FOR_COLLECT');
                return;
            }

            $this->session->refresh();

            // ==================== PHASE 2: RESET (if collect done) ====================
            if ($this->session->needsReset()) {
                Log::info("[JOB:{$sessionId}] Starting RESET phase");
                
                $resetSuccess = $this->runResetPhase();
                if (!$resetSuccess) {
                    return;
                }
            }

            $this->session->refresh();
            
            // ==================== PHASE 3: START PARSING (if ready) ====================
            if ($this->session->canStartParsing()) {
                Log::info("[JOB:{$sessionId}] Starting PARSING phase");
                
                // Verify pending URLs exist
                $pendingCount = SupplierUrl::forSupplier($this->supplier)
                    ->where('is_valid', true)
                    ->where('status', SupplierUrl::STATUS_PENDING)
                    ->count();

                if ($pendingCount === 0) {
                    Log::error("[JOB:{$sessionId}] FATAL: No pending URLs after reset");
                    $this->session->fail(
                        'NO_PENDING_AFTER_RESET',
                        "После reset pending_count=0 — нечего парсить"
                    );
                    return;
                }
                
                Log::info("[JOB:{$sessionId}] Pending URLs verified", ['count' => $pendingCount]);
                $this->session->update([
                    'total_urls' => $pendingCount,
                ]);
                $this->session->startParsing();
            } elseif ($this->session->hasParsingStarted()) {
                Log::info("[JOB:{$sessionId}] SKIP init: parsing already started");
            }

            $this->session->refresh();

            // ==================== PHASE 4: PARSE ONE BATCH ====================
            if ($this->session->getLifecycleStatus() === ParsingSession::LIFECYCLE_PARSING) {
                $parseSuccess = $this->runParseBatch();
                
                if (!$parseSuccess) {
                    // Session already marked as failed
                    return;
                }

                // ==================== PHASE 5: CHECK IF MORE WORK ====================
                if ($this->hasMoreWork()) {
                    Log::info("[JOB:{$sessionId}] More URLs to process, re-queuing");
                    
                    // Re-queue job for next batch (same session, no collect/reset)
                    dispatch(new self(
                        $this->session->fresh(),
                        $this->supplier,
                        $this->config
                    ))->delay(now()->addSeconds(5));
                } else {
                    // All done
                    Log::info("[JOB:{$sessionId}] All URLs processed, marking completed");
                    $this->session->markParsingCompleted();
                }
            }

        } catch (ProcessTimedOutException $e) {
            Log::error("[JOB:{$sessionId}] TIMEOUT - marking as FAILED (no restart)", [
                'exception' => get_class($e),
            ]);
            $this->session->fail('PROCESS_TIMEOUT', $e->getMessage());
            
        } catch (\Exception $e) {
            Log::error("[JOB:{$sessionId}] EXCEPTION - marking as FAILED (no restart)", [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            $this->session->fail('JOB_EXCEPTION', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Run collect phase - EXACTLY ONCE per session.
     */
    protected function runCollectPhase(): bool
    {
        $sessionId = $this->session->id;
        
        // Mark collecting started
        $this->session->startCollecting();

        $pythonPath = config('parser.python_path', 'python3');
        $callbackUrl = config('parser.callback_url');
        $callbackToken = config('parser.callback_token');

        $command = [
            $pythonPath,
            '-m',
            'parser.main',
            $this->supplier,
            '--collect-only',
            '--session-id', (string) $sessionId,
            '--api-callback', $callbackUrl,
            '--api-token', $callbackToken,
        ];

        Log::info("[JOB:{$sessionId}] Running collect", [
            'command' => implode(' ', $command),
            'timeout' => self::COLLECT_TIMEOUT,
            'phase' => 'collect',
        ]);

        $process = new Process($command, '/var/www/html');
        $process->setEnv(['PYTHONPATH' => '/var/www/html']);
        $process->setTimeout(self::COLLECT_TIMEOUT); // Dynamic timeout for collect
        
        $process->run(function ($type, $buffer) use ($sessionId) {
            Log::info("[COLLECT:{$sessionId}] " . rtrim($buffer));
            // Update heartbeat
            $this->session->update(['last_heartbeat_at' => now()]);
        });

        if (!$process->isSuccessful()) {
            $exitCode = $process->getExitCode();
            $error = Str::limit($process->getErrorOutput(), 500);
            Log::error("[JOB:{$sessionId}] COLLECT FAILED", [
                'exit_code' => $exitCode,
                'error' => $error,
                'phase' => 'collect',
            ]);
            $this->session->fail('COLLECT_FAILED', "Exit {$exitCode}: {$error}");
            return false;
        }

        // Get collected URL count
        $urlCount = SupplierUrl::forSupplier($this->supplier)
            ->where('is_valid', true)
            ->count();

        $this->session->markCollectingDone($urlCount);
        Log::info("[JOB:{$sessionId}] COLLECT DONE", ['urls_collected' => $urlCount]);

        return true;
    }

    /**
     * Run reset phase - EXACTLY ONCE per session.
     * Transitions: collected → resetting → ready_to_parse
     */
    protected function runResetPhase(): bool
    {
        $sessionId = $this->session->id;

        // Transition to resetting state
        $this->session->startResetting();
        $this->session->update(['reset_started_at' => now()]);

        $pythonPath = config('parser.python_path', 'python3');
        $callbackUrl = config('parser.callback_url');
        $callbackToken = config('parser.callback_token');

        $command = [
            $pythonPath,
            '-m',
            'parser.main',
            $this->supplier,
            '--reset-only',
            '--session-id', (string) $sessionId,
            '--api-callback', $callbackUrl,
            '--api-token', $callbackToken,
        ];

        Log::info("[JOB:{$sessionId}] Running reset", [
            'command' => implode(' ', $command),
            'timeout' => self::RESET_TIMEOUT,
            'phase' => 'reset',
        ]);

        $process = new Process($command, '/var/www/html');
        $process->setEnv(['PYTHONPATH' => '/var/www/html']);
        $process->setTimeout(self::RESET_TIMEOUT);
        
        $process->run(function ($type, $buffer) use ($sessionId) {
            Log::info("[RESET:{$sessionId}] " . rtrim($buffer));
        });

        if (!$process->isSuccessful()) {
            $exitCode = $process->getExitCode();
            $error = Str::limit($process->getErrorOutput(), 500);
            Log::error("[JOB:{$sessionId}] RESET FAILED", [
                'exit_code' => $exitCode,
                'error' => $error,
                'phase' => 'reset',
            ]);
            $this->session->fail('RESET_FAILED', "Exit {$exitCode}: {$error}");
            return false;
        }

        // Transition to ready_to_parse state
        $this->session->markResetDone();
        $this->session->update(['reset_finished_at' => now()]);
        Log::info("[JOB:{$sessionId}] RESET DONE, ready to parse");

        return true;
    }

    /**
     * Run one batch of parsing.
     */
    protected function runParseBatch(): bool
    {
        $sessionId = $this->session->id;
        
        $pythonPath = config('parser.python_path', 'python3');
        $callbackUrl = config('parser.callback_url');
        $callbackToken = config('parser.callback_token');
        $batchSize = self::BATCH_SIZE;
        $concurrency = (int) config('parser.queue_concurrency', 3);
        $minRequestInterval = (float) config('parser.request_delay', 0.0);

        $command = [
            $pythonPath,
            '-m',
            'parser.main',
            $this->supplier,
            '--queue',
            '--batch-size', (string) $batchSize,
            '--max-batches', '1',  // ONE batch per job!
            '--concurrency', (string) $concurrency,
            '--min-request-interval', (string) $minRequestInterval,
            '--session-id', (string) $sessionId,
            '--api-callback', $callbackUrl,
            '--api-token', $callbackToken,
        ];

        Log::info("[JOB:{$sessionId}] Running parse batch", [
            'batch_size' => $batchSize,
            'concurrency' => $concurrency,
            'timeout' => self::PARSE_BATCH_TIMEOUT,
            'phase' => 'parse',
        ]);

        $process = new Process($command, '/var/www/html');
        $process->setEnv([
            'PYTHONPATH' => '/var/www/html',
            'PARSER_REQUEST_DELAY' => (string) $minRequestInterval,
        ]);
        $process->setTimeout(self::PARSE_BATCH_TIMEOUT);

        $pid = null;
        $process->start(function ($type, $buffer) use ($sessionId) {
            Log::info("[PARSE:{$sessionId}] " . rtrim($buffer));
            // Update heartbeat during parsing
            $this->session->update(['last_heartbeat_at' => now()]);
        });

        $pid = $process->getPid();
        $this->session->update(['pid' => $pid]);
        Log::info("[JOB:{$sessionId}] Parser process started", ['pid' => $pid]);

        $process->wait();

        if (!$process->isSuccessful()) {
            $exitCode = $process->getExitCode();
            $error = Str::limit($process->getErrorOutput(), 500);
            
            Log::error("[JOB:{$sessionId}] PARSE BATCH FAILED", [
                'exit_code' => $exitCode,
                'error' => $error,
                'phase' => 'parse',
            ]);

            $this->session->fail('PARSE_BATCH_FAILED', "Exit {$exitCode}: {$error}");
            return false;
        }

        Log::info("[JOB:{$sessionId}] Parse batch completed");
        return true;
    }

    /**
     * Check if there are more URLs to process.
     * Only returns true if there are actual pending URLs.
     */
    protected function hasMoreWork(): bool
    {
        $pendingCount = SupplierUrl::forSupplier($this->supplier)
            ->claimable()
            ->count();
        
        Log::info("[JOB:{$this->session->id}] Checking pending URLs", [
            'pending_count' => $pendingCount,
        ]);
        
        return $pendingCount > 0;
    }
    
    /**
     * Check blocked ratio and fail if too high.
     */
    protected function checkBlockedRatio(): bool
    {
        $total = SupplierUrl::forSupplier($this->supplier)->count();
        $blocked = SupplierUrl::forSupplier($this->supplier)
            ->where('status', SupplierUrl::STATUS_BLOCKED)
            ->count();
        
        if ($total === 0) {
            return true;
        }
        
        $blockedRatio = $blocked / $total;
        $maxBlockedRatio = 0.8; // 80%
        
        if ($blockedRatio >= $maxBlockedRatio) {
            Log::error("[JOB:{$this->session->id}] TOO_MANY_BLOCKED", [
                'blocked' => $blocked,
                'total' => $total,
                'ratio' => round($blockedRatio * 100, 1) . '%',
            ]);
            $this->session->fail('TOO_MANY_BLOCKED', "Blocked {$blocked}/{$total} ({$blockedRatio}%)");
            return false;
        }
        
        return true;
    }

    /**
     * Handle job failure - mark session as failed (NO RETRY).
     */
    public function failed(\Throwable $exception): void
    {
        $sessionId = $this->session->id ?? 'unknown';
        
        Log::error("[JOB:{$sessionId}] JOB FAILED HANDLER - NO RETRY", [
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
        ]);

        if (!$this->session->isTerminal()) {
            $this->session->fail('JOB_FAILED', $exception->getMessage());
        }
    }
}
