<?php

namespace App\Domain\PriceIndices\Domain\Classifiers;

use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatisticalClassifierActiveVersion extends Model
{
    protected $primaryKey = 'classifier_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'classifier_id',
        'classifier_version_id',
        'activated_at',
        'activated_by',
        'activation_reason',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $pointer): void {
            $versionClassifierId = StatisticalClassifierVersion::query()
                ->whereKey($pointer->classifier_version_id)
                ->value('classifier_id');

            if ($versionClassifierId === null
                || (int) $versionClassifierId !== (int) $pointer->classifier_id
            ) {
                throw new PriceIndicesInvariantViolation(
                    'Active classifier version must belong to the same classifier.'
                );
            }
        });
    }

    protected function casts(): array
    {
        return ['activated_at' => 'datetime'];
    }

    public function classifier(): BelongsTo
    {
        return $this->belongsTo(StatisticalClassifier::class, 'classifier_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(StatisticalClassifierVersion::class, 'classifier_version_id');
    }

    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }
}
