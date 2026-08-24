<?php

namespace App\Domain\PriceIndices\Domain\Classifiers;

use App\Domain\PriceIndices\Domain\Concerns\HasPublicId;
use Database\Factories\StatisticalClassifierFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StatisticalClassifier extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'code',
        'standard_code',
        'name',
        'issuing_authority',
        'responsible_body',
        'official_distributor',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(StatisticalClassifierVersion::class, 'classifier_id');
    }

    public function sourceFiles(): HasMany
    {
        return $this->hasMany(StatisticalClassifierSourceFile::class, 'classifier_id');
    }

    public function imports(): HasMany
    {
        return $this->hasMany(StatisticalClassifierImport::class, 'classifier_id');
    }

    public function activeVersionPointer(): HasOne
    {
        return $this->hasOne(StatisticalClassifierActiveVersion::class, 'classifier_id');
    }

    protected static function newFactory(): Factory
    {
        return StatisticalClassifierFactory::new();
    }
}
