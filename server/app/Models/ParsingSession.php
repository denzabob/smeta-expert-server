<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * ParsingSession with deterministic lifecycle (Anti-Loop).
 * 
 * LIFECYCLE STATUSES (finite state machine):
 * - created: Session created, nothing started
 * - collecting: URL collection in progress
 * - collect_done: Collection finished, ready for parsing
 * - parsing: Parsing in progress
 * - completed: All work finished successfully
 * - failed: Terminated due to error (no auto-restart)
 * - aborted: Manually stopped by user/system
 * 
 * TRANSITION RULES:
 * - created → collecting (only once)
 * - collecting → collect_done | failed
 * - collect_done → parsing (only once)
 * - parsing → completed | failed
 * - ANY → aborted (manual stop)
 * - failed/completed/aborted are TERMINAL states (no transitions out)
 */
class ParsingSession extends Model
{
    // ==================== LIFECYCLE STATUS CONSTANTS ====================
    // NOTE: lifecycle_status is VARCHAR, status is DB enum
    // DB status enum: 'pending','running','completed','failed','stopped','canceling'
    
    // Lifecycle statuses (stored in lifecycle_status VARCHAR column)
    public const LIFECYCLE_CREATED = 'created';
    public const LIFECYCLE_COLLECTING = 'collecting';
    public const LIFECYCLE_COLLECTED = 'collected';       // After collect done, before reset
    public const LIFECYCLE_RESETTING = 'resetting';       // Reset in progress  
    public const LIFECYCLE_READY_TO_PARSE = 'ready_to_parse';  // After reset
    public const LIFECYCLE_PARSING = 'parsing';
    public const LIFECYCLE_FINISHED_SUCCESS = 'finished_success';  // Terminal: all done
    public const LIFECYCLE_FINISHED_FAILED = 'finished_failed';    // Terminal: error
    
    // DB status values (must match DB enum exactly!)
    public const DB_STATUS_PENDING = 'pending';
    public const DB_STATUS_RUNNING = 'running';
    public const DB_STATUS_COMPLETED = 'completed';
    public const DB_STATUS_FAILED = 'failed';
    public const DB_STATUS_STOPPED = 'stopped';
    public const DB_STATUS_CANCELING = 'canceling';
    
    // Aliases for backward compatibility
    public const STATUS_CREATED = self::LIFECYCLE_CREATED;
    public const STATUS_COLLECTING = self::LIFECYCLE_COLLECTING;
    public const STATUS_COLLECTED = self::LIFECYCLE_COLLECTED;
    public const STATUS_COLLECT_DONE = self::LIFECYCLE_COLLECTED;
    public const STATUS_RESETTING = self::LIFECYCLE_RESETTING;
    public const STATUS_READY_TO_PARSE = self::LIFECYCLE_READY_TO_PARSE;
    public const STATUS_PARSING = self::LIFECYCLE_PARSING;
    public const STATUS_COMPLETED = self::LIFECYCLE_FINISHED_SUCCESS;
    public const STATUS_FINISHED_SUCCESS = self::LIFECYCLE_FINISHED_SUCCESS;
    public const STATUS_FAILED = self::LIFECYCLE_FINISHED_FAILED;
    public const STATUS_FINISHED_FAILED = self::LIFECYCLE_FINISHED_FAILED;
    public const STATUS_ABORTED = self::LIFECYCLE_FINISHED_FAILED;
    
    // Terminal states - no transitions allowed out of these
    public const TERMINAL_STATUSES = [
        self::LIFECYCLE_FINISHED_SUCCESS,
        self::LIFECYCLE_FINISHED_FAILED,
    ];
    
    // Valid lifecycle status transitions (strict FSM)
    public const STATUS_TRANSITIONS = [
        self::LIFECYCLE_CREATED => [self::LIFECYCLE_COLLECTING, self::LIFECYCLE_FINISHED_FAILED],
        self::LIFECYCLE_COLLECTING => [self::LIFECYCLE_COLLECTED, self::LIFECYCLE_FINISHED_FAILED],
        self::LIFECYCLE_COLLECTED => [self::LIFECYCLE_RESETTING, self::LIFECYCLE_FINISHED_FAILED],
        self::LIFECYCLE_RESETTING => [self::LIFECYCLE_READY_TO_PARSE, self::LIFECYCLE_FINISHED_FAILED],
        self::LIFECYCLE_READY_TO_PARSE => [self::LIFECYCLE_PARSING, self::LIFECYCLE_FINISHED_FAILED],
        self::LIFECYCLE_PARSING => [self::LIFECYCLE_FINISHED_SUCCESS, self::LIFECYCLE_FINISHED_FAILED],
        self::LIFECYCLE_FINISHED_SUCCESS => [], // Terminal
        self::LIFECYCLE_FINISHED_FAILED => [], // Terminal
    ];
    
    // ==================== MODEL CONFIGURATION ====================

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'parsing_sessions';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'supplier_name',
        'status',
        'started_at',
        'finished_at',
        'pages_processed',
        'items_updated',
        'errors_count',
        'pid',
        'last_heartbeat',
        'total_urls',
        'full_scan_run_id',
        'full_scan_prepared_at',
        'full_scan_stage',
        'error_reason',
        'stop_reason',
        // Lifecycle fields
        'lifecycle_status',
        'result_status',
        'collect_started_at',
        'collect_finished_at',
        'collect_urls_count',
        'collect_stats_json',
        'reset_started_at',
        'reset_finished_at',
        'parse_started_at',
        'parse_finished_at',
        'parse_stats_json',
        'session_run_id',
        'failed_reason',
        'failed_at',
        'aborted_by',
        'aborted_at',
        'max_collect_pages',
        'max_collect_urls',
        'max_collect_time_seconds',
        'job_dispatched_at',
        'job_attempts',
        'last_heartbeat_at',
        'failed_details',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'last_heartbeat' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'full_scan_prepared_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        // Lifecycle casts
        'collect_started_at' => 'datetime',
        'collect_finished_at' => 'datetime',
        'reset_started_at' => 'datetime',
        'reset_finished_at' => 'datetime',
        'parse_started_at' => 'datetime',
        'parse_finished_at' => 'datetime',
        'failed_at' => 'datetime',
        'aborted_at' => 'datetime',
        'job_dispatched_at' => 'datetime',
        // JSON casts
        'collect_stats_json' => 'array',
        'parse_stats_json' => 'array',
        'failed_details' => 'array',
    ];

    // ==================== BOOT ====================
    
    protected static function boot()
    {
        parent::boot();
        
        // Generate unique session_run_id on creation
        static::creating(function ($session) {
            if (empty($session->lifecycle_status)) {
                $session->lifecycle_status = self::STATUS_CREATED;
            }
            if (empty($session->session_run_id)) {
                $session->session_run_id = Str::uuid()->toString();
            }
        });
    }

    /**
     * Get all logs for this session.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(ParsingLog::class, 'session_id');
    }

    /**
     * Get logs filtered by level.
     */
    public function errorLogs()
    {
        return $this->logs()->where('level', 'error');
    }

    /**
     * Get logs filtered by level.
     */
    public function warningLogs()
    {
        return $this->logs()->where('level', 'warning');
    }

    /**
     * Mark session as running.
     */
    public function markAsRunning(): self
    {
        $this->update([
            'status' => self::DB_STATUS_RUNNING,
            'started_at' => now(),
        ]);

        return $this;
    }

    /**
     * Mark session as completed.
     */
    public function markAsCompleted(): self
    {
        $this->update([
            'status' => self::DB_STATUS_COMPLETED,
            'lifecycle_status' => self::LIFECYCLE_FINISHED_SUCCESS,
            'finished_at' => now(),
        ]);

        return $this;
    }

    /**
     * Mark session as failed with error message.
     */
    public function markAsFailed(string $errorMessage, int $exitCode = 1): self
    {
        $this->update([
            'status' => self::DB_STATUS_FAILED,
            'lifecycle_status' => self::LIFECYCLE_FINISHED_FAILED,
            'finished_at' => now(),
        ]);

        return $this;
    }

    /**
     * Mark session as stopped by user.
     */
    public function markAsStopped(): self
    {
        $this->update([
            'status' => self::DB_STATUS_STOPPED,
            'lifecycle_status' => self::LIFECYCLE_FINISHED_FAILED,
            'finished_at' => now(),
        ]);

        return $this;
    }

    /**
     * Mark session as canceling.
     */
    public function markAsCanceling(): self
    {
        $this->update(['status' => self::DB_STATUS_CANCELING]);

        return $this;
    }

    /**
     * Add a log entry to this session.
     */
    public function addLog(string $level, string $message, ?array $details = null): ParsingLog
    {
        return $this->logs()->create([
            'level' => $level,
            'message' => $message,
            'url' => '', // Default value for required url field
        ]);
    }

    /**
     * Get execution duration in seconds.
     */
    public function getDurationSeconds(): ?int
    {
        if (!$this->started_at) {
            return null;
        }

        $endTime = $this->finished_at ?? now();
        return (int) $endTime->diffInSeconds($this->started_at);
    }

    /**
     * Get percentage of completion.
     */
    public function getProgressPercent(): float
    {
        // Если нет обработанных страниц, прогресс 0%
        if ($this->pages_processed === 0) {
            return 0;
        }

        // Базовый прогресс зависит от количества обработанных страниц
        // и статуса сессии
        if ($this->status === self::DB_STATUS_COMPLETED) {
            return 100;
        }
        
        // Check lifecycle status for terminal success
        if ($this->lifecycle_status === self::LIFECYCLE_FINISHED_SUCCESS) {
            return 100;
        }

        // Для running сессий - простая прогрессия от количества обработанных
        // В данном случае используем items_updated как индикатор прогресса
        return min($this->items_updated * 10, 95); // Макс 95% до завершения
    }

    /**
     * Check if session is still running.
     */
    public function isRunning(): bool
    {
        return in_array($this->status, [
            self::DB_STATUS_PENDING,
            self::DB_STATUS_RUNNING,
            self::DB_STATUS_CANCELING,
        ]);
    }

    /**
     * Check if session is completed (terminal state).
     */
    public function isCompleted(): bool
    {
        return in_array($this->status, [
            self::DB_STATUS_COMPLETED,
            self::DB_STATUS_FAILED,
            self::DB_STATUS_STOPPED,
        ]);
    }

    /**
     * Update heartbeat timestamp (called on each callback).
     */
    public function updateHeartbeat(): self
    {
        $this->update(['last_heartbeat_at' => now()]);
        return $this;
    }

    /**
     * Check if heartbeat is stale (no callbacks for X minutes).
     */
    public function isHeartbeatStale(int $minutes = 10): bool
    {
        if (!$this->last_heartbeat_at || !$this->isRunning()) {
            return false;
        }

        return now()->diffInMinutes($this->last_heartbeat_at) >= $minutes;
    }

    /**
     * Check if process is zombie (PID exists but status is running).
     */
    public function hasZombieProcess(): bool
    {
        if (!$this->pid || $this->status !== 'running') {
            return false;
        }

        // На Linux/Unix проверяем процесс
        if (PHP_OS_FAMILY === 'Linux') {
            return !file_exists("/proc/{$this->pid}");
        }

        return false;
    }

    // ==================== FULL-SCAN STAGE MANAGEMENT ====================

    /**
     * Check if full-scan preparation (collect + reset) is done for this run_id.
     */
    public function isFullScanPrepared(string $runId): bool
    {
        return $this->full_scan_run_id === $runId
            && in_array($this->full_scan_stage, ['reset_done', 'parsing_running', 'parsing_done']);
    }

    /**
     * Mark collect phase as done.
     */
    public function markCollectDone(string $runId): self
    {
        $this->update([
            'full_scan_run_id' => $runId,
            'full_scan_stage' => 'collect_done',
        ]);
        return $this;
    }

    /**
     * Mark parsing as running.
     */
    public function markParsingRunning(): self
    {
        $this->update([
            'full_scan_stage' => 'parsing_running',
        ]);
        return $this;
    }

    /**
     * Mark parsing as done.
     */
    public function markParsingDone(): self
    {
        $this->update([
            'full_scan_stage' => 'parsing_done',
        ]);
        return $this;
    }

    /**
     * Mark session as failed with specific reason.
     */
    public function markAsFailedWithReason(string $reason, ?string $errorMessage = null): self
    {
        $this->update([
            'status' => self::DB_STATUS_FAILED,
            'lifecycle_status' => self::LIFECYCLE_FINISHED_FAILED,
            'finished_at' => now(),
            'failed_at' => now(),
            'error_reason' => $reason,
            'failed_reason' => $errorMessage,
        ]);
        
        Log::warning("[SESSION:{$this->id}] Marked as FAILED", [
            'reason' => $reason,
            'message' => $errorMessage,
        ]);
        
        return $this;
    }
    
    // ==================== DETERMINISTIC LIFECYCLE METHODS (ANTI-LOOP) ====================
    
    /**
     * Get current lifecycle status.
     */
    public function getLifecycleStatus(): string
    {
        return $this->lifecycle_status ?? self::LIFECYCLE_CREATED;
    }
    
    /**
     * Check if status transition is allowed.
     */
    public function canTransitionTo(string $newStatus): bool
    {
        $currentStatus = $this->getLifecycleStatus();
        $allowedTransitions = self::STATUS_TRANSITIONS[$currentStatus] ?? [];
        
        return in_array($newStatus, $allowedTransitions);
    }
    
    /**
     * Check if session is in terminal state (no more work possible).
     */
    public function isTerminal(): bool
    {
        return in_array($this->getLifecycleStatus(), self::TERMINAL_STATUSES);
    }
    
    /**
     * Validate and perform status transition.
     * Throws exception if transition is not allowed.
     */
    protected function transitionTo(string $newStatus, array $extraData = []): self
    {
        $currentStatus = $this->getLifecycleStatus();
        
        if (!$this->canTransitionTo($newStatus)) {
            Log::error("[SESSION:{$this->id}] INVALID TRANSITION BLOCKED", [
                'from' => $currentStatus,
                'to' => $newStatus,
            ]);
            
            throw new \RuntimeException(
                "Invalid session transition: {$currentStatus} → {$newStatus} (session_id={$this->id})"
            );
        }
        
        Log::info("[SESSION:{$this->id}] Lifecycle transition", [
            'from' => $currentStatus,
            'to' => $newStatus,
        ]);
        
        $this->update(array_merge(['lifecycle_status' => $newStatus], $extraData));
        
        return $this;
    }
    
    // ==================== COLLECT PHASE MANAGEMENT ====================
    
    /**
     * Check if collect has already been executed for this session.
     * ANTI-LOOP: Prevents re-running collect.
     */
    public function hasCollectExecuted(): bool
    {
        $status = $this->getLifecycleStatus();
        // Any status beyond 'created' means collect has been started/done
        return $status !== self::LIFECYCLE_CREATED;
    }
    
    /**
     * Check if collect is allowed to start.
     * Only from 'created' status.
     */
    public function canStartCollect(): bool
    {
        return $this->getLifecycleStatus() === self::LIFECYCLE_CREATED;
    }
    
    /**
     * Check if resetting is needed (after collect done).
     */
    public function needsReset(): bool
    {
        return $this->getLifecycleStatus() === self::LIFECYCLE_COLLECTED;
    }
    
    /**
     * Start resetting phase (after collect).
     */
    public function startResetting(): self
    {
        if ($this->getLifecycleStatus() !== self::LIFECYCLE_COLLECTED) {
            Log::warning("[SESSION:{$this->id}] SKIP startResetting: not in collected state", [
                'current_status' => $this->getLifecycleStatus(),
            ]);
            return $this;
        }
        
        return $this->transitionTo(self::LIFECYCLE_RESETTING, [
            // full_scan_stage stays at 'collect_done' during reset
        ]);
    }
    
    /**
     * Mark reset as complete, ready for parsing.
     */
    public function markResetDone(): self
    {
        if ($this->getLifecycleStatus() !== self::LIFECYCLE_RESETTING) {
            Log::warning("[SESSION:{$this->id}] SKIP markResetDone: not in resetting state", [
                'current_status' => $this->getLifecycleStatus(),
            ]);
            return $this;
        }
        
        return $this->transitionTo(self::LIFECYCLE_READY_TO_PARSE, [
            'full_scan_stage' => 'reset_done',
            'full_scan_prepared_at' => now(),
        ]);
    }
    
    /**
     * Start collecting phase.
     * MUST be called ONLY ONCE per session.
     */
    public function startCollecting(): self
    {
        if ($this->hasCollectExecuted()) {
            Log::warning("[SESSION:{$this->id}] SKIP collect: already executed", [
                'current_status' => $this->getLifecycleStatus(),
            ]);
            return $this;
        }
        
        return $this->transitionTo(self::LIFECYCLE_COLLECTING, [
            'status' => self::DB_STATUS_RUNNING,
            'collect_started_at' => now(),
            'started_at' => now(),
            // full_scan_stage stays at 'not_started' during collecting
        ]);
    }
    
    /**
     * Mark collect as done with URL count.
     */
    public function markCollectingDone(int $urlsCollected = 0): self
    {
        if ($this->getLifecycleStatus() !== self::LIFECYCLE_COLLECTING) {
            Log::warning("[SESSION:{$this->id}] SKIP markCollectingDone: not in collecting state", [
                'current_status' => $this->getLifecycleStatus(),
            ]);
            return $this;
        }
        
        return $this->transitionTo(self::LIFECYCLE_COLLECTED, [
            'collect_finished_at' => now(),
            'collect_urls_count' => $urlsCollected,
            'total_urls' => $urlsCollected,
            'full_scan_stage' => 'collect_done',
        ]);
    }
    
    // ==================== PARSING PHASE MANAGEMENT ====================
    
    /**
     * Check if parsing has already been started for this session.
     * ANTI-LOOP: Prevents re-running reset/parse init.
     */
    public function hasParsingStarted(): bool
    {
        $status = $this->getLifecycleStatus();
        return in_array($status, [
            self::LIFECYCLE_PARSING,
            self::LIFECYCLE_FINISHED_SUCCESS,
        ]);
    }
    
    /**
     * Check if parsing is allowed to start.
     * Only from 'collected' status after reset.
     */
    public function canStartParsing(): bool
    {
        return $this->getLifecycleStatus() === self::LIFECYCLE_READY_TO_PARSE;
    }
    
    /**
     * Start parsing phase.
     * MUST be called ONLY ONCE per session.
     */
    public function startParsing(): self
    {
        if ($this->hasParsingStarted()) {
            Log::warning("[SESSION:{$this->id}] SKIP startParsing: already started", [
                'current_status' => $this->getLifecycleStatus(),
            ]);
            return $this;
        }
        
        return $this->transitionTo(self::LIFECYCLE_PARSING, [
            'status' => self::DB_STATUS_RUNNING,
            'parse_started_at' => now(),
            'full_scan_stage' => 'parsing_running',
        ]);
    }
    
    /**
     * Mark parsing as completed.
     */
    public function markParsingCompleted(): self
    {
        if ($this->getLifecycleStatus() !== self::LIFECYCLE_PARSING) {
            Log::warning("[SESSION:{$this->id}] SKIP markParsingCompleted: not in parsing state", [
                'current_status' => $this->getLifecycleStatus(),
            ]);
            return $this;
        }
        
        return $this->transitionTo(self::LIFECYCLE_FINISHED_SUCCESS, [
            'status' => self::DB_STATUS_COMPLETED,
            'parse_finished_at' => now(),
            'finished_at' => now(),
            'full_scan_stage' => 'parsing_done',
        ]);
    }
    
    // ==================== FAILURE & ABORT MANAGEMENT ====================
    
    /**
     * Mark session as failed with full error details (terminal state - NO AUTO-RESTART).
     * 
     * SAFE ERROR RECORDING:
     * - failed_reason: short code (max 100 chars) - e.g. PROCESS_TIMEOUT, COLLECT_FAILED
     * - failed_details: full JSON payload with exit_code, stderr_tail, exception_class, etc.
     * 
     * Any exception during recording will NOT prevent session from being marked failed.
     */
    public function fail(string $reason, ?string $message = null, array $details = []): self
    {
        if ($this->isTerminal()) {
            Log::warning("[SESSION:{$this->id}] SKIP fail: already terminal", [
                'current_status' => $this->getLifecycleStatus(),
            ]);
            return $this;
        }
        
        try {
            // Prepare safe values
            $safeReason = substr($reason, 0, 100);
            $safeErrorReason = substr($reason, 0, 255);
            
            // Build failed_details JSON
            $failedDetails = array_merge([
                'reason' => $reason,
                'message_short' => $message ? substr($message, 0, 500) : null,
                'phase' => $this->getLifecycleStatus(),
                'timestamp' => now()->toIso8601String(),
            ], $details);
            
            // Force transition to failed
            $this->update([
                'lifecycle_status' => self::LIFECYCLE_FINISHED_FAILED,
                'status' => self::DB_STATUS_FAILED,
                'failed_at' => now(),
                'finished_at' => now(),
                'error_reason' => $safeErrorReason,
                'failed_reason' => $safeReason,
                'failed_details' => json_encode($failedDetails, JSON_UNESCAPED_UNICODE),
            ]);
            
        } catch (\Exception $e) {
            // CRITICAL: Even if details fail, still mark as failed
            Log::error("[SESSION:{$this->id}] Error recording failure details", [
                'exception' => $e->getMessage(),
            ]);
            
            try {
                // Minimal update - should always succeed
                $this->update([
                    'lifecycle_status' => self::LIFECYCLE_FINISHED_FAILED,
                    'status' => self::DB_STATUS_FAILED,
                    'failed_at' => now(),
                    'finished_at' => now(),
                    'error_reason' => 'FAIL_RECORD_ERROR',
                    'failed_reason' => 'FAIL_RECORD_ERROR',
                ]);
            } catch (\Exception $e2) {
                Log::critical("[SESSION:{$this->id}] CRITICAL: Cannot mark session as failed", [
                    'exception' => $e2->getMessage(),
                ]);
            }
        }
        
        Log::error("[SESSION:{$this->id}] FAILED - NO AUTO-RESTART", [
            'reason' => $reason,
            'message' => $message ? substr($message, 0, 200) : null,
        ]);
        
        return $this;
    }
    
    /**
     * Fail with full error payload from process.
     */
    public function failWithProcessError(
        string $reason, 
        int $exitCode, 
        string $stderr, 
        ?string $exceptionClass = null
    ): self {
        return $this->fail($reason, "Exit {$exitCode}", [
            'exit_code' => $exitCode,
            'stderr_tail' => substr($stderr, -4000), // Last 4KB of stderr
            'exception_class' => $exceptionClass,
        ]);
    }
    
    /**
     * Abort session (manual stop - terminal state).
     */
    public function abort(string $abortedBy = 'user'): self
    {
        if ($this->isTerminal()) {
            Log::warning("[SESSION:{$this->id}] SKIP abort: already terminal", [
                'current_status' => $this->getLifecycleStatus(),
            ]);
            return $this;
        }
        
        // Force transition to aborted (allowed from any non-terminal state)
        $this->update([
            'lifecycle_status' => self::LIFECYCLE_FINISHED_FAILED,
            'status' => self::DB_STATUS_STOPPED,
            'aborted_at' => now(),
            'aborted_by' => $abortedBy,
            'finished_at' => now(),
            // Don't set full_scan_stage - keep current stage value
        ]);
        
        Log::info("[SESSION:{$this->id}] ABORTED", [
            'by' => $abortedBy,
        ]);
        
        return $this;
    }
    
    // ==================== JOB DISPATCH GUARDS ====================
    
    /**
     * Check if job dispatch is allowed.
     * ANTI-LOOP: Prevents multiple dispatches for same session.
     */
    public function canDispatchJob(): bool
    {
        // Already dispatched
        if ($this->job_dispatched_at !== null) {
            return false;
        }
        
        // Terminal state
        if ($this->isTerminal()) {
            return false;
        }
        
        // Only dispatch from created state
        return $this->getLifecycleStatus() === self::LIFECYCLE_CREATED;
    }
    
    /**
     * Mark job as dispatched.
     */
    public function markJobDispatched(): self
    {
        $this->update([
            'job_dispatched_at' => now(),
            'job_attempts' => ($this->job_attempts ?? 0) + 1,
        ]);
        
        return $this;
    }
    
    // ==================== SESSION STATE EXPORT FOR PYTHON ====================
    
    /**
     * Export session state for Python parser.
     * Used by API to communicate lifecycle state.
     */
    public function exportStateForParser(): array
    {
        return [
            'session_id' => $this->id,
            'session_run_id' => $this->session_run_id,
            'lifecycle_status' => $this->getLifecycleStatus(),
            'can_collect' => $this->canStartCollect(),
            'can_parse' => $this->canStartParsing(),
            'has_collect_executed' => $this->hasCollectExecuted(),
            'has_parsing_started' => $this->hasParsingStarted(),
            'is_terminal' => $this->isTerminal(),
            'collect_urls_count' => $this->collect_urls_count ?? 0,
            'limits' => [
                'max_collect_pages' => $this->max_collect_pages,
                'max_collect_urls' => $this->max_collect_urls,
                'max_collect_time_seconds' => $this->max_collect_time_seconds,
            ],
        ];
    }
}
