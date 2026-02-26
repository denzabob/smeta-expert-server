<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PriceImportSession extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'price_import_sessions';

    protected $fillable = [
        'user_id',
        'price_list_version_id',
        'supplier_id',
        'target_type',
        'file_path',
        'storage_disk',
        'original_filename',
        'file_type',
        'file_hash',
        'status',
        'header_row_index',
        'sheet_index',
        'column_mapping',
        'options',
        'raw_rows',
        'resolution_queue',
        'stats',
        'result',
        'error_message',
        'error_details',
    ];

    protected $casts = [
        'column_mapping' => 'array',
        'options' => 'array',
        'raw_rows' => 'array',
        'resolution_queue' => 'array',
        'stats' => 'array',
        'result' => 'array',
        'error_details' => 'array',
        'header_row_index' => 'integer',
        'sheet_index' => 'integer',
    ];

    // Status constants (state machine)
    public const STATUS_CREATED = 'created';
    public const STATUS_PARSING_FAILED = 'parsing_failed';
    public const STATUS_MAPPING_REQUIRED = 'mapping_required';
    public const STATUS_RESOLUTION_REQUIRED = 'resolution_required';
    public const STATUS_EXECUTION_RUNNING = 'execution_running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_EXECUTION_FAILED = 'execution_failed';
    public const STATUS_CANCELLED = 'cancelled';

    // Target types
    public const TARGET_OPERATIONS = 'operations';
    public const TARGET_MATERIALS = 'materials';

    // File types
    public const FILE_TYPE_XLSX = 'xlsx';
    public const FILE_TYPE_XLS = 'xls';
    public const FILE_TYPE_CSV = 'csv';
    public const FILE_TYPE_HTML = 'html';
    public const FILE_TYPE_PASTE = 'paste';

    /**
     * Get the user that owns this session.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the price list version.
     */
    public function priceListVersion(): BelongsTo
    {
        return $this->belongsTo(PriceListVersion::class);
    }

    /**
     * Get the supplier.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Get storage path for the file.
     */
    public function getStoragePath(): ?string
    {
        if (!$this->file_path) {
            return null;
        }
        // Use Storage facade to get the correct path based on disk configuration
        $disk = $this->storage_disk ?? 'local';
        return Storage::disk($disk)->path($this->file_path);
    }

    /**
     * Check if mapping can be applied.
     */
    public function canApplyMapping(): bool
    {
        return in_array($this->status, [
            self::STATUS_CREATED,
            self::STATUS_MAPPING_REQUIRED,
            self::STATUS_RESOLUTION_REQUIRED,
        ]);
    }

    /**
     * Check if dry run can be executed.
     */
    public function canRunDryRun(): bool
    {
        return $this->status === self::STATUS_MAPPING_REQUIRED 
            && !empty($this->column_mapping);
    }

    /**
     * Check if execution can start.
     */
    public function canExecute(): bool
    {
        return in_array($this->status, [
            self::STATUS_RESOLUTION_REQUIRED,
            self::STATUS_EXECUTION_RUNNING, // Allow retry if stuck
            self::STATUS_EXECUTION_FAILED,  // Allow retry after failed execution
        ]);
    }

    /**
     * Check if session is in terminal state.
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_PARSING_FAILED,
            self::STATUS_EXECUTION_FAILED,
            self::STATUS_CANCELLED,
        ]);
    }

    /**
     * Transition to new status.
     */
    public function transitionTo(string $status): bool
    {
        $allowedTransitions = [
            self::STATUS_CREATED => [self::STATUS_PARSING_FAILED, self::STATUS_MAPPING_REQUIRED],
            self::STATUS_MAPPING_REQUIRED => [self::STATUS_RESOLUTION_REQUIRED, self::STATUS_MAPPING_REQUIRED],
            self::STATUS_RESOLUTION_REQUIRED => [self::STATUS_EXECUTION_RUNNING, self::STATUS_RESOLUTION_REQUIRED],
            self::STATUS_EXECUTION_RUNNING => [self::STATUS_COMPLETED, self::STATUS_EXECUTION_FAILED],
        ];

        if (!isset($allowedTransitions[$this->status])) {
            return false;
        }

        if (!in_array($status, $allowedTransitions[$this->status])) {
            return false;
        }

        $this->status = $status;
        return $this->save();
    }

    /**
     * Set parsing failed status with error message.
     */
    public function markParsingFailed(string $message, ?array $details = null): void
    {
        $this->status = self::STATUS_PARSING_FAILED;
        $this->error_message = $message;
        $this->error_details = $details;
        $this->save();
    }

    /**
     * Set execution failed status with error message.
     */
    public function markExecutionFailed(string $message, ?array $details = null): void
    {
        $this->status = self::STATUS_EXECUTION_FAILED;
        $this->error_message = $message;
        $this->error_details = $details;
        $this->save();
    }

    /**
     * Update stats.
     */
    public function updateStats(array $stats): void
    {
        $this->stats = array_merge($this->stats ?? [], $stats);
        $this->save();
    }

    /**
     * Get option with default.
     */
    public function getOption(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * Scope for user sessions.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope for pending sessions (not terminal).
     */
    public function scopePending($query)
    {
        return $query->whereNotIn('status', [
            self::STATUS_COMPLETED,
            self::STATUS_PARSING_FAILED,
            self::STATUS_EXECUTION_FAILED,
            self::STATUS_CANCELLED,
        ]);
    }
}
