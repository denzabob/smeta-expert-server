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
 * ResetUrlsJob - Reset URL statuses for full-scan.
 * 
 * TIMEOUT: 180s (should be very fast)
 * 
 * On success → dispatches ParseBatchJob
 * On failure → marks session as failed (NO RETRY)
 * 
 * ANTI-LOOP:
 * - Runs EXACTLY ONCE per session
 * - If already reset, skips and dispatches next phase
 */
class ResetUrlsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 180;
    public $deleteWhenMissingModels = true;

    protected const PROCESS_TIMEOUT = 120;

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
        Log::info("[RESET:{$sessionId}] ResetUrlsJob started");

        $this->session->refresh();

        // GUARD: Check if reset needed
        $status = $this->session->getLifecycleStatus();
        
        if (in_array($status, [
            ParsingSession::LIFECYCLE_READY_TO_PARSE,
            ParsingSession::LIFECYCLE_PARSING,
            ParsingSession::LIFECYCLE_FINISHED_SUCCESS,
        ])) {
            Log::info("[RESET:{$sessionId}] SKIP: Already reset, dispatching ParseBatchJob");
            $this->dispatchNextPhase();
            return;
        }

        // GUARD: Terminal state
        if ($this->session->isTerminal()) {
            Log::warning("[RESET:{$sessionId}] SKIP: Session is terminal");
            return;
        }

        // Must be in collected state
        if ($status !== ParsingSession::LIFECYCLE_COLLECTED) {
            Log::error("[RESET:{$sessionId}] Invalid state for reset", ['status' => $status]);
            $this->session->fail('INVALID_STATE_FOR_RESET', "Expected collected, got {$status}");
            return;
        }

        // Start resetting
        $this->session->startResetting();
        $this->session->update(['reset_started_at' => now()]);

        try {
            $success = $this->runResetProcess();

            if (!$success) {
                return;
            }

            // Verify pending count
            $pendingCount = SupplierUrl::forSupplier($this->supplier)
                ->where('status', SupplierUrl::STATUS_PENDING)
                ->count();

            if ($pendingCount === 0) {
                Log::error("[RESET:{$sessionId}] FATAL: No pending URLs after reset");
                $this->session->fail('NO_PENDING_AFTER_RESET', "Reset succeeded but pending_count=0");
                return;
            }

            // Mark reset done
            $this->session->markResetDone();
            $this->session->update(['reset_finished_at' => now()]);
            
            Log::info("[RESET:{$sessionId}] Reset done", ['pending_count' => $pendingCount]);

            // Dispatch parsing
            $this->dispatchNextPhase();

        } catch (ProcessTimedOutException $e) {
            Log::error("[RESET:{$sessionId}] TIMEOUT");
            $this->session->failWithProcessError(
                'RESET_TIMEOUT',
                -1,
                "Process timed out after " . self::PROCESS_TIMEOUT . "s",
                get_class($e)
            );
        } catch (\Exception $e) {
            Log::error("[RESET:{$sessionId}] Exception", ['message' => $e->getMessage()]);
            $this->session->failWithProcessError(
                'RESET_EXCEPTION',
                -1,
                $e->getMessage(),
                get_class($e)
            );
        }
    }

    protected function runResetProcess(): bool
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
            '--reset-only',
            '--session-id', (string) $sessionId,
            '--api-callback', $callbackUrl,
            '--api-token', $callbackToken,
        ];

        Log::info("[RESET:{$sessionId}] Running", [
            'command' => implode(' ', $command),
            'timeout' => self::PROCESS_TIMEOUT,
        ]);

        $process = new Process($command, '/var/www/html');
        $process->setEnv(['PYTHONPATH' => '/var/www/html']);
        $process->setTimeout(self::PROCESS_TIMEOUT);

        $process->run(function ($type, $buffer) use ($sessionId) {
            Log::info("[RESET:{$sessionId}] " . rtrim($buffer));
        });

        if (!$process->isSuccessful()) {
            $exitCode = $process->getExitCode();
            $stderr = $process->getErrorOutput();

            Log::error("[RESET:{$sessionId}] FAILED", [
                'exit_code' => $exitCode,
                'stderr_tail' => Str::limit($stderr, 500),
            ]);

            $this->session->failWithProcessError(
                'RESET_FAILED',
                $exitCode,
                $stderr
            );
            return false;
        }

        return true;
    }

    protected function dispatchNextPhase(): void
    {
        $this->session->refresh();
        
        // Start parsing lifecycle
        if ($this->session->canStartParsing()) {
            $this->session->startParsing();
        }

        Log::info("[RESET:{$this->session->id}] Dispatching ParseBatchJob");
        
        ParseBatchJob::dispatch($this->session->fresh(), $this->supplier, $this->config)
            ->onQueue('parsing');
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("[RESET:{$this->session->id}] JOB FAILED", [
            'exception' => $exception->getMessage(),
        ]);

        if (!$this->session->isTerminal()) {
            $this->session->failWithProcessError(
                'RESET_JOB_FAILED',
                -1,
                $exception->getMessage(),
                get_class($exception)
            );
        }
    }
}
