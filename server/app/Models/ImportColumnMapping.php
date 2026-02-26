<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportColumnMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_session_id',
        'column_index',
        'field',
    ];

    protected $casts = [
        'column_index' => 'integer',
    ];

    /**
     * Field constants
     */
    public const FIELD_WIDTH = 'width';
    public const FIELD_LENGTH = 'length';
    public const FIELD_QTY = 'qty';
    public const FIELD_NAME = 'name';
    public const FIELD_IGNORE = 'ignore';
    // Facade-specific fields
    public const FIELD_KIND = 'kind';
    public const FIELD_PRICE_ITEM_CODE = 'price_item_code';
    public const FIELD_HEIGHT = 'height';

    /**
     * Fields that must be unique per session (only one mapping allowed).
     */
    public const UNIQUE_FIELDS = [
        self::FIELD_WIDTH,
        self::FIELD_LENGTH,
        self::FIELD_QTY,
        self::FIELD_NAME,
        self::FIELD_KIND,
        self::FIELD_PRICE_ITEM_CODE,
        self::FIELD_HEIGHT,
    ];

    /**
     * Required fields for a valid mapping (minimum).
     */
    public const REQUIRED_FIELDS = [
        self::FIELD_WIDTH,
        self::FIELD_LENGTH,
    ];

    /**
     * All available field options.
     */
    public const ALL_FIELDS = [
        self::FIELD_WIDTH,
        self::FIELD_LENGTH,
        self::FIELD_QTY,
        self::FIELD_NAME,
        self::FIELD_IGNORE,
        self::FIELD_KIND,
        self::FIELD_PRICE_ITEM_CODE,
        self::FIELD_HEIGHT,
    ];

    /**
     * Get the import session this mapping belongs to.
     */
    public function importSession(): BelongsTo
    {
        return $this->belongsTo(ImportSession::class);
    }

    /**
     * Check if this mapping is for a required field.
     */
    public function isRequiredField(): bool
    {
        return in_array($this->field, self::REQUIRED_FIELDS);
    }

    /**
     * Check if this mapping should be ignored.
     */
    public function isIgnored(): bool
    {
        return $this->field === self::FIELD_IGNORE || $this->field === null;
    }
}
