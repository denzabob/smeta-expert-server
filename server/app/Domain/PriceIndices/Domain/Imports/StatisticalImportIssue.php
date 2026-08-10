<?php

namespace App\Domain\PriceIndices\Domain\Imports;

use App\Domain\PriceIndices\Domain\Concerns\HasPublicId;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportIssueSeverity;
use Database\Factories\StatisticalImportIssueFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatisticalImportIssue extends Model
{
    use HasFactory, HasPublicId;

    public const UPDATED_AT = null;

    protected $fillable = [
        'import_id',
        'severity',
        'code',
        'message',
        'sheet_name',
        'source_row',
        'source_column',
        'classifier_item_code',
        'details_json',
    ];

    protected function casts(): array
    {
        return [
            'severity' => StatisticalImportIssueSeverity::class,
            'source_row' => 'integer',
            'details_json' => 'array',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(StatisticalImport::class, 'import_id');
    }

    protected static function newFactory(): Factory
    {
        return StatisticalImportIssueFactory::new();
    }
}
