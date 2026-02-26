<?php

namespace App\Jobs\Parsing;

use App\Models\ParsingSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * StartFullScanJob - Orchestrator for full-scan workflow.
 * 
 * Creates session and kicks off the pipeline:
 * StartFullScanJob → CollectUrlsJob → ResetUrlsJob → ParseBatchJob (loop)
 * 
 * ANTI-LOOP RULES:
 * 1. Only creates session once
 * 2. Does NOT re-dispatch if session already started
 * 3. Each phase job handles its own re-dispatch
 */
class StartFullScanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 60;
    public $deleteWhenMissingModels = true;

    protected string $supplier;
    protected array $config;
    protected ?int $sessionId;

    public function __construct(string $supplier, array $config = [], ?int $sessionId = null)
    {
        $this->supplier = $supplier;
        $this->config = $config;
        $this->sessionId = $sessionId;
    }

    public function handle(): void
    {
        Log::info("[ORCHESTRATOR] StartFullScanJob for {$this->supplier}");

        // Get or create session
        $session = $this->sessionId 
            ? ParsingSession::find($this->sessionId)
            : $this->createSession();

        if (!$session) {
            Log::error("[ORCHESTRATOR] Session not found: {$this->sessionId}");
            return;
        }

        // Check if already running
        if ($session->isTerminal()) {
            Log::warning("[ORCHESTRATOR] Session {$session->id} is terminal, skipping");
            return;
        }

        if (!$session->canDispatchJob()) {
            Log::warning("[ORCHESTRATOR] Session {$session->id} cannot dispatch, skipping", [
                'job_dispatched_at' => $session->job_dispatched_at,
                'lifecycle_status' => $session->getLifecycleStatus(),
            ]);
            return;
        }

        // Mark as dispatched
        $session->markJobDispatched();

        // Start the pipeline with CollectUrlsJob
        Log::info("[ORCHESTRATOR] Dispatching CollectUrlsJob for session {$session->id}");
        
        CollectUrlsJob::dispatch($session, $this->supplier, $this->config)
            ->onQueue('parsing');
    }

    protected function createSession(): ParsingSession
    {
        // Check for existing active session
        $existing = ParsingSession::where('supplier_name', $this->supplier)
            ->whereIn('status', [
                ParsingSession::DB_STATUS_PENDING,
                ParsingSession::DB_STATUS_RUNNING,
            ])
            ->first();

        if ($existing) {
            Log::info("[ORCHESTRATOR] Using existing session {$existing->id}");
            return $existing;
        }

        // Create new session
        $session = ParsingSession::create([
            'supplier_name' => $this->supplier,
            'status' => ParsingSession::DB_STATUS_PENDING,
            'lifecycle_status' => ParsingSession::LIFECYCLE_CREATED,
            'max_collect_pages' => $this->config['max_collect_pages'] ?? null,
            'max_collect_urls' => $this->config['max_collect_urls'] ?? null,
            'max_collect_time_seconds' => $this->config['max_collect_time_seconds'] ?? null,
        ]);

        Log::info("[ORCHESTRATOR] Created session {$session->id}");
        return $session;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("[ORCHESTRATOR] StartFullScanJob failed", [
            'supplier' => $this->supplier,
            'exception' => $exception->getMessage(),
        ]);
    }
}
