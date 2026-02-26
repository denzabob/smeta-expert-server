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
 * CollectUrlsJob - Collect URLs from supplier catalog.
 * 
 * TIMEOUT: 720s (hard_limit=600 + 120s buffer)
 * 
 * On success → dispatches ResetUrlsJob
 * On failure → marks session as failed (NO RETRY)
 * 
 * ANTI-LOOP:
 * - Runs EXACTLY ONCE per session
 * - If already collected, skips and dispatches next phase
 */
class CollectUrlsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * No retry - failure is terminal.
     */
    public $tries = 1;

    /**
     * Timeout: hard_limit (600) + buffer (120) = 720s
     */
    public $timeout = 720;

    public $deleteWhenMissingModels = true;

    /**
     * Python process timeout (must be < job timeout)
     */
    protected const PROCESS_TIMEOUT = 660; // hard_limit + 60s margin

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
        Log::info("[COLLECT:{$sessionId}] CollectUrlsJob started");

        $this->session->refresh();

        // GUARD: Already collected
        if ($this->session->hasCollectExecuted()) {
            Log::info("[COLLECT:{$sessionId}] SKIP: Already collected, dispatching ResetUrlsJob");
            $this->dispatchNextPhase();
            return;
        }

        // GUARD: Terminal state
        if ($this->session->isTerminal()) {
            Log::warning("[COLLECT:{$sessionId}] SKIP: Session is terminal");
            return;
        }

        // Start collecting
        $this->session->startCollecting();

        try {
            $success = $this->runCollectProcess();

            if (!$success) {
                // Session already marked as failed
                return;
            }

            // Get collected URL count from session stats (single source of truth)
            $freshSession = $this->session->fresh();
            [$urlCount, $source, $collectStats] = $this->getCollectedCountFromSessionStats($freshSession);

            $this->session->markCollectingDone((int) $urlCount);
            Log::info("[COLLECT:{$sessionId}] Collect done", [
                'urls_collected' => $urlCount,
                'urls_collected_source' => $source,
                'collect_stats' => $collectStats,
            ]);

            // Dispatch next phase
            $this->dispatchNextPhase();

        } catch (ProcessTimedOutException $e) {
            Log::error("[COLLECT:{$sessionId}] TIMEOUT");
            $this->session->failWithProcessError(
                'COLLECT_TIMEOUT',
                -1,
                "Process timed out after " . self::PROCESS_TIMEOUT . "s",
                get_class($e)
            );
        } catch (\Exception $e) {
            Log::error("[COLLECT:{$sessionId}] Exception", [
                'message' => $e->getMessage(),
            ]);
            $this->session->failWithProcessError(
                'COLLECT_EXCEPTION',
                -1,
                $e->getMessage(),
                get_class($e)
            );
        }
    }

    protected function runCollectProcess(): bool
    {
        $sessionId = $this->session->id;

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

        Log::info("[COLLECT:{$sessionId}] Running", [
            'command' => implode(' ', $command),
            'timeout' => self::PROCESS_TIMEOUT,
        ]);

        $process = new Process($command, '/var/www/html');
        $process->setEnv(['PYTHONPATH' => '/var/www/html']);
        $process->setTimeout(self::PROCESS_TIMEOUT);

        $process->run(function ($type, $buffer) use ($sessionId) {
            Log::info("[COLLECT:{$sessionId}] " . rtrim($buffer));
            $this->session->update(['last_heartbeat_at' => now()]);
        });

        if (!$process->isSuccessful()) {
            $exitCode = $process->getExitCode();
            $stderr = $process->getErrorOutput();
            
            Log::error("[COLLECT:{$sessionId}] FAILED", [
                'exit_code' => $exitCode,
                'stderr_tail' => Str::limit($stderr, 500),
            ]);

            $this->session->failWithProcessError(
                'COLLECT_FAILED',
                $exitCode,
                $stderr
            );
            return false;
        }

        return true;
    }

    protected function getCollectedCountFromSessionStats(ParsingSession $session): array
    {
        $collectStats = $session->collect_stats_json ?? [];

        if (isset($collectStats['urls_sent_total'])) {
            return [(int) $collectStats['urls_sent_total'], 'collect_stats_json.urls_sent_total', $collectStats];
        }

        if (isset($collectStats['urls_unique_total'])) {
            return [(int) $collectStats['urls_unique_total'], 'collect_stats_json.urls_unique_total', $collectStats];
        }

        if (!empty($session->collect_urls_count)) {
            return [(int) $session->collect_urls_count, 'session.collect_urls_count', $collectStats];
        }

        // Fallback (not ideal) - count by supplier as last resort
        $fallbackCount = SupplierUrl::forSupplier($this->supplier)
            ->where('is_valid', true)
            ->count();

        return [$fallbackCount, 'fallback_supplier_count', $collectStats];
    }

    protected function dispatchNextPhase(): void
    {
        Log::info("[COLLECT:{$this->session->id}] Dispatching ResetUrlsJob");
        
        ResetUrlsJob::dispatch($this->session->fresh(), $this->supplier, $this->config)
            ->onQueue('parsing');
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("[COLLECT:{$this->session->id}] JOB FAILED", [
            'exception' => $exception->getMessage(),
        ]);

        if (!$this->session->isTerminal()) {
            $this->session->failWithProcessError(
                'COLLECT_JOB_FAILED',
                -1,
                $exception->getMessage(),
                get_class($exception)
            );
        }
    }
}
