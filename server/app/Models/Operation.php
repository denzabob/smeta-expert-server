<?php
// app/Models/Operation.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Illuminate\Support\Facades\Auth;

class Operation extends Model
{
    use HasFactory;

    public const KIND_CUTTING = 'cutting';
    public const KIND_EDGING = 'edging';
    public const KIND_DRILLING = 'drilling';
    public const KIND_OTHER = 'other';

    public const AUTO_KIND_TO_EXCLUSION_GROUP = [
        self::KIND_CUTTING => 'cutting',
        self::KIND_EDGING => 'edging',
        self::KIND_DRILLING => 'drilling',
    ];

    protected $fillable = [
        'name',
        'search_name',
        'category',
        'operation_kind',
        'exclusion_group',
        'min_thickness',
        'max_thickness',
        'unit',
        'description',
        'user_id',
        'origin',
    ];

    protected $casts = [
        'min_thickness' => 'decimal:2',
        'max_thickness' => 'decimal:2',
    ];

    // Автоматически устанавливать user_id, origin и search_name при создании/обновлении
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($operation) {
            if (!isset($operation->user_id)) {
                $operation->user_id = Auth::id();
            }
            if (!isset($operation->origin)) {
                $operation->origin = $operation->user_id ? 'user' : 'system';
            }
            // Generate search_name
            if (!isset($operation->search_name) && isset($operation->name)) {
                $operation->search_name = self::normalizeSearchName($operation->name);
            }
        });

        static::saving(function ($operation) {
            $normalized = self::normalizeOperationKindAndExclusionGroup($operation->getAttributes());
            $operation->operation_kind = $normalized['operation_kind'] ?? null;
            $operation->exclusion_group = $normalized['exclusion_group'] ?? null;

            self::assertOperationKindAndExclusionGroupConsistency($normalized);
        });

        static::updating(function ($operation) {
            // Update search_name if name changed
            if ($operation->isDirty('name')) {
                $operation->search_name = self::normalizeSearchName($operation->name);
            }
        });
    }

    /**
     * Normalize name for search.
     */
    public static function normalizeSearchName(string $name): string
    {
        $name = mb_strtolower($name, 'UTF-8');
        $name = preg_replace('/["\',;:!?\.]+/', '', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        return trim($name);
    }

    public static function allowedKinds(): array
    {
        return [
            self::KIND_CUTTING,
            self::KIND_EDGING,
            self::KIND_DRILLING,
            self::KIND_OTHER,
        ];
    }

    public static function inferOperationKind(?string $exclusionGroup, ?string $category): string
    {
        $normalizedGroup = self::normalizeExclusionGroup($exclusionGroup);
        $normalizedCategory = self::normalizeTextValue($category);

        return match (true) {
            $normalizedGroup === self::AUTO_KIND_TO_EXCLUSION_GROUP[self::KIND_CUTTING] => self::KIND_CUTTING,
            $normalizedGroup === self::AUTO_KIND_TO_EXCLUSION_GROUP[self::KIND_EDGING] => self::KIND_EDGING,
            $normalizedGroup === self::AUTO_KIND_TO_EXCLUSION_GROUP[self::KIND_DRILLING],
            $normalizedCategory === self::KIND_DRILLING => self::KIND_DRILLING,
            default => self::KIND_OTHER,
        };
    }

    public static function normalizeOperationKindAndExclusionGroup(array $data): array
    {
        $normalized = $data;
        $normalizedKind = self::normalizeOperationKindValue($data['operation_kind'] ?? null);
        $normalizedGroup = self::normalizeExclusionGroup($data['exclusion_group'] ?? null);
        $normalizedCategory = self::normalizeTextValue($data['category'] ?? null);

        if ($normalizedKind === null) {
            $normalizedKind = self::inferOperationKind($normalizedGroup, $normalizedCategory);
        }

        $normalized['operation_kind'] = $normalizedKind;

        if (isset(self::AUTO_KIND_TO_EXCLUSION_GROUP[$normalizedKind])) {
            $normalized['exclusion_group'] = self::AUTO_KIND_TO_EXCLUSION_GROUP[$normalizedKind];
        } else {
            $normalized['exclusion_group'] = $normalizedGroup;
        }

        return $normalized;
    }

    public static function assertOperationKindAndExclusionGroupConsistency(array $data): void
    {
        $kind = self::normalizeOperationKindValue($data['operation_kind'] ?? null);
        $group = self::normalizeExclusionGroup($data['exclusion_group'] ?? null);

        if ($kind === null || !in_array($kind, self::allowedKinds(), true)) {
            throw new InvalidArgumentException('invalid_operation_kind');
        }

        if ($kind === self::KIND_OTHER && self::isAutoExclusionGroup($group)) {
            throw new InvalidArgumentException('invalid_exclusion_group_for_other_kind');
        }
    }

    public static function isAutoExclusionGroup(?string $group): bool
    {
        return $group !== null
            && in_array($group, array_values(self::AUTO_KIND_TO_EXCLUSION_GROUP), true);
    }

    private static function normalizeOperationKindValue(mixed $value): ?string
    {
        $normalized = self::normalizeTextValue($value);

        return $normalized === null ? null : $normalized;
    }

    private static function normalizeExclusionGroup(mixed $value): ?string
    {
        return self::normalizeTextValue($value);
    }

    private static function normalizeTextValue(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = mb_strtolower(trim($value), 'UTF-8');

        return $normalized === '' ? null : $normalized;
    }

    public function scopeOwnOrSystem(Builder $query, ?int $userId = null): Builder
    {
        $userId ??= Auth::id();

        return $query->where(function (Builder $scopeQuery) use ($userId) {
            $scopeQuery->where('user_id', $userId)
                ->orWhere(function (Builder $systemQuery) {
                    $systemQuery->whereNull('user_id')
                        ->whereIn('origin', ['system', 'parser']);
                });
        });
    }

    /**
     * Get prices from all versions.
     */
    public function prices()
    {
        return $this->hasMany(OperationPrice::class);
    }

    /**
     * Get aliases.
     */
    public function aliases()
    {
        return $this->hasMany(SupplierProductAlias::class, 'internal_item_id')
            ->where('internal_item_type', 'operation');
    }

    public function priceSources()
    {
        return $this->hasMany(OperationPriceSource::class);
    }

    public function applicationRules()
    {
        return $this->hasMany(OperationApplicationRule::class);
    }
}
