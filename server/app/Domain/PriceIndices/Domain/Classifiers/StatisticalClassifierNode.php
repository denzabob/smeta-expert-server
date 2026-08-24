<?php

namespace App\Domain\PriceIndices\Domain\Classifiers;

use App\Domain\PriceIndices\Domain\Concerns\HasPublicId;
use App\Domain\PriceIndices\Domain\Enums\ClassifierSemanticLevel;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use Database\Factories\StatisticalClassifierNodeFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StatisticalClassifierNode extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'classifier_version_id',
        'code',
        'name',
        'normalized_name',
        'semantic_level',
        'formal_depth',
        'parent_node_id',
        'source_order',
        'notes_text',
        'metadata_json',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $node): void {
            if ($node->parent_node_id === null) {
                return;
            }

            $parentVersionId = self::query()
                ->whereKey($node->parent_node_id)
                ->value('classifier_version_id');

            if ($parentVersionId === null
                || (int) $parentVersionId !== (int) $node->classifier_version_id
            ) {
                throw new PriceIndicesInvariantViolation(
                    'Classifier node and parent must belong to the same classifier version.'
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'semantic_level' => ClassifierSemanticLevel::class,
            'formal_depth' => 'integer',
            'source_order' => 'integer',
            'metadata_json' => 'array',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(StatisticalClassifierVersion::class, 'classifier_version_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_node_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_node_id');
    }

    protected static function newFactory(): Factory
    {
        return StatisticalClassifierNodeFactory::new();
    }
}
