<?php

namespace App\Domain\PriceIndices\Domain\Classifiers;

use App\Domain\PriceIndices\Domain\Concerns\HasPublicId;
use App\Domain\PriceIndices\Domain\Enums\ClassifierVersionStatus;
use Database\Factories\StatisticalClassifierVersionFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StatisticalClassifierVersion extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'classifier_id',
        'version_label',
        'effective_from',
        'effective_to',
        'approved_at',
        'source_published_at',
        'status',
        'node_count',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'approved_at' => 'date',
            'source_published_at' => 'datetime',
            'status' => ClassifierVersionStatus::class,
            'node_count' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function classifier(): BelongsTo
    {
        return $this->belongsTo(StatisticalClassifier::class, 'classifier_id');
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(StatisticalClassifierNode::class, 'classifier_version_id');
    }

    public function activePointer(): HasOne
    {
        return $this->hasOne(StatisticalClassifierActiveVersion::class, 'classifier_version_id');
    }

    protected static function newFactory(): Factory
    {
        return StatisticalClassifierVersionFactory::new();
    }
}
