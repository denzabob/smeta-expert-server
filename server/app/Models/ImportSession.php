<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'project_id',
        'file_path',
        'storage_disk',
        'original_filename',
        'file_type',
        'status',
        'header_row_index',
        'sheet_index',
        'options',
        'result',
    ];

    protected $casts = [
        'options' => 'array',
        'result' => 'array',
        'header_row_index' => 'integer',
        'sheet_index' => 'integer',
    ];

    /**
     * Status constants
     */
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_MAPPED = 'mapped';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_FAILED = 'failed';

    /**
     * File type constants
     */
    public const FILE_TYPE_XLSX = 'xlsx';
    public const FILE_TYPE_XLS = 'xls';
    public const FILE_TYPE_CSV = 'csv';

    /**
     * Get the user that owns this import session.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the project this import is associated with.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the column mappings for this import session.
     */
    public function columnMappings(): HasMany
    {
        return $this->hasMany(ImportColumnMapping::class);
    }

    /**
     * Check if this session is an Excel file (xlsx/xls).
     */
    public function isExcel(): bool
    {
        return in_array($this->file_type, [self::FILE_TYPE_XLSX, self::FILE_TYPE_XLS]);
    }

    /**
     * Check if this session is a CSV file.
     */
    public function isCsv(): bool
    {
        return $this->file_type === self::FILE_TYPE_CSV;
    }

    /**
     * Get an option value with a default.
     */
    public function getOption(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * Get the full storage path for the file.
     */
    public function getFullFilePath(): string
    {
        return \Storage::disk($this->storage_disk)->path($this->file_path);
    }

    /**
     * Get the mapping for a specific field.
     */
    public function getMappingForField(string $field): ?ImportColumnMapping
    {
        return $this->columnMappings->firstWhere('field', $field);
    }

    /**
     * Get the column index for a specific field.
     */
    public function getColumnIndexForField(string $field): ?int
    {
        $mapping = $this->getMappingForField($field);
        return $mapping?->column_index;
    }
}
