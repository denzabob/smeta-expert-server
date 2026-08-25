<?php

namespace App\Domain\PriceIndices\Domain\Classifiers;

use App\Domain\PriceIndices\Domain\Concerns\HasPublicId;
use App\Domain\PriceIndices\Domain\Enums\ClassifierItemMappingReviewStatus;
use App\Domain\PriceIndices\Domain\Enums\ClassifierItemMappingType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatisticalClassifierItemMapping extends Model
{
    use HasPublicId;

    public const AUTOMATIC_METHOD_PREFIX = 'automatic:';

    protected $fillable = [
        'statistical_classifier_item_id',
        'classifier_version_id',
        'classifier_node_id',
        'mapping_type',
        'review_status',
        'method',
        'confidence',
        'evidence_json',
        'confirmed_at',
        'confirmed_by',
    ];

    protected function casts(): array
    {
        return [
            'mapping_type' => ClassifierItemMappingType::class,
            'review_status' => ClassifierItemMappingReviewStatus::class,
            'confidence' => 'decimal:4',
            'evidence_json' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }

    public function isOperatorOwned(): bool
    {
        return $this->review_status === ClassifierItemMappingReviewStatus::Rejected
            || $this->confirmed_by !== null
            || ! str_starts_with($this->method, self::AUTOMATIC_METHOD_PREFIX);
    }

    public function classifierItem(): BelongsTo
    {
        return $this->belongsTo(StatisticalClassifierItem::class, 'statistical_classifier_item_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(StatisticalClassifierVersion::class, 'classifier_version_id');
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(StatisticalClassifierNode::class, 'classifier_node_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
