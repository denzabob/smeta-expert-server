<?php

namespace App\Jobs\Parsing;

use App\Models\ParsingSession;
use App\Models\SupplierUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ParseBatchJob - Parse one batch of URLs.
 * 
 * TIMEOUT: 1800s (30 min per batch)
 * 
 * On success + more URLs → dispatches another ParseBatchJob
 * On success + no more URLs → marks session completed
 * On failure → marks session as failed (NO RETRY)
 * 
 * ANTI-LOOP:
 * - Only re-dispatches if pending > 0
 * - Checks blocked ratio before starting
 * - Limited retries on transient errors
 */
class ParseBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 1800; // 30 minutes
    public $deleteWhenMissingModels = true;

    /**
     * Python process timeout.
     * batch_size * p95_time + buffer = 30 * 30s + 300 = 1200s
     */
    protected const PROCESS_TIMEOUT = 1500;
    
    protected const BATCH_SIZE = 30;
    protected const MAX_BLOCKED_RATIO = 0.8; // 80%

    protected ParsingSession $session;
    protected string $supplier;
    protected array $config;

    public function __construct(ParsingSession $session, string $supplier, array $config = [])
    {
        $this->session = $session;
        $this->supplier = $supplier;
        $this->config = $config;
    }

    public function handle(): void
    {
        $sessionId = $this->session->id;
        Log::info("[PARSE:{$sessionId}] ParseBatchJob started");

        $this->session->refresh();

        // GUARD: Terminal state
        if ($this->session->isTerminal()) {
            Log::warning("[PARSE:{$sessionId}] SKIP: Session is terminal");
            return;
        }

        // GUARD: Not in parsing state
        if ($this->session->getLifecycleStatus() !== ParsingSession::LIFECYCLE_PARSING) {
            Log::warning("[PARSE:{$sessionId}] SKIP: Not in parsing state", [
                'status' => $this->session->getLifecycleStatus(),
            ]);
            return;
        }

        // GUARD: Check blocked ratio
        if (!$this->checkBlockedRatio()) {
            return; // Session already marked as failed
        }

        // Check if there's work to do
        $pendingCount = $this->getPendingCount();
        if ($pendingCount === 0) {
            Log::info("[PARSE:{$sessionId}] No pending URLs, marking completed");
            $this->session->markParsingCompleted();
            return;
        }

        $currentTotal = (int) ($this->session->total_urls ?? 0);
        if ($pendingCount > $currentTotal) {
            $this->session->update(['total_urls' => $pendingCount]);
        }

        try {
            $success = $this->runParseBatch();

            if (!$success) {
                return; // Session already marked as failed
            }

            // Check if more work
            $newPendingCount = $this->getPendingCount();
            
            if ($newPendingCount > 0) {
                Log::info("[PARSE:{$sessionId}] More URLs to process", [
                    'pending_before' => $pendingCount,
                    'pending_after' => $newPendingCount,
                ]);
                
                // Re-dispatch for next batch
                self::dispatch($this->session->fresh(), $this->supplier, $this->config)
                    ->onQueue('parsing')
                    ->delay(now()->addSeconds(5));
            } else {
                Log::info("[PARSE:{$sessionId}] All URLs processed, marking completed");
                $this->session->markParsingCompleted();
            }

        } catch (ProcessTimedOutException $e) {
            Log::error("[PARSE:{$sessionId}] TIMEOUT");
            $this->session->failWithProcessError(
                'PARSE_TIMEOUT',
                -1,
                "Batch timed out after " . self::PROCESS_TIMEOUT . "s",
                get_class($e)
            );
        } catch (\Exception $e) {
            Log::error("[PARSE:{$sessionId}] Exception", ['message' => $e->getMessage()]);
            $this->session->failWithProcessError(
                'PARSE_EXCEPTION',
                -1,
                $e->getMessage(),
                get_class($e)
            );
        }
    }

    protected function runParseBatch(): bool
    {
        $sessionId = $this->session->id;

        $pythonPath = config('parser.python_path', 'python3');
        $callbackUrl = config('parser.callback_url');
        $callbackToken = config('parser.callback_token');
        $batchSize = $this->config['batch_size'] ?? self::BATCH_SIZE;
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

        Log::info("[PARSE:{$sessionId}] Running batch", [
            'batch_size' => $batchSize,
            'concurrency' => $concurrency,
            'timeout' => self::PROCESS_TIMEOUT,
        ]);

        $process = new Process($command, '/var/www/html');
        $process->setEnv([
            'PYTHONPATH' => '/var/www/html',
            'PARSER_REQUEST_DELAY' => (string) $minRequestInterval,
        ]);
        $process->setTimeout(self::PROCESS_TIMEOUT);

        $pid = null;
        $process->start(function ($type, $buffer) use ($sessionId) {
            Log::info("[PARSE:{$sessionId}] " . rtrim($buffer));
            $this->session->update(['last_heartbeat_at' => now()]);
        });

        $pid = $process->getPid();
        $this->session->update(['pid' => $pid]);
        Log::info("[PARSE:{$sessionId}] Process started", ['pid' => $pid]);

        $process->wait();

        if (!$process->isSuccessful()) {
            $exitCode = $process->getExitCode();
            $stderr = $process->getErrorOutput();

            Log::error("[PARSE:{$sessionId}] Batch FAILED", [
                'exit_code' => $exitCode,
                'stderr_tail' => Str::limit($stderr, 500),
            ]);

            $this->session->failWithProcessError(
                'PARSE_BATCH_FAILED',
                $exitCode,
                $stderr
            );
            return false;
        }

        Log::info("[PARSE:{$sessionId}] Batch completed");
        return true;
    }

    protected function getPendingCount(): int
    {
        return SupplierUrl::forSupplier($this->supplier)
            ->where('status', SupplierUrl::STATUS_PENDING)
            ->where('is_valid', true)
            ->count();
    }

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

        if ($blockedRatio >= self::MAX_BLOCKED_RATIO) {
            Log::error("[PARSE:{$this->session->id}] TOO_MANY_BLOCKED", [
                'blocked' => $blocked,
                'total' => $total,
                'ratio' => round($blockedRatio * 100, 1) . '%',
            ]);
            
            $this->session->fail(
                'TOO_MANY_BLOCKED',
                "Blocked {$blocked}/{$total} (" . round($blockedRatio * 100, 1) . "%)",
                [
                    'blocked_count' => $blocked,
                    'total_count' => $total,
                    'blocked_ratio' => $blockedRatio,
                ]
            );
            return false;
        }

        return true;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("[PARSE:{$this->session->id}] JOB FAILED", [
            'exception' => $exception->getMessage(),
        ]);

        if (!$this->session->isTerminal()) {
            $this->session->failWithProcessError(
                'PARSE_JOB_FAILED',
                -1,
                $exception->getMessage(),
                get_class($exception)
            );
        }
    }
}
