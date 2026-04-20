<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationApplicationRule extends Model
{
    public const MODE_AUTOMATIC = 'automatic';

    public const APPLIES_TO_MATERIAL_TYPE = 'material_type';

    public const APPLIES_TO_MATERIAL_ID = 'material_id';

    public const QUANTITY_SOURCE_POSITION_AREA_M2 = 'position_area_m2';

    public const QUANTITY_SOURCE_POSITION_QUANTITY = 'position_quantity';

    public const QUANTITY_SOURCE_EDGE_LENGTH = 'edge_length';

    public const QUANTITY_SOURCE_HOLES_COUNT = 'holes_count';

    public const TARIFF_BINDING_OPERATION_RESOLVER = 'operation_resolver';

    public static function supportedQuantitySources(): array
    {
        return [
            self::QUANTITY_SOURCE_POSITION_AREA_M2,
            self::QUANTITY_SOURCE_POSITION_QUANTITY,
            self::QUANTITY_SOURCE_EDGE_LENGTH,
            self::QUANTITY_SOURCE_HOLES_COUNT,
        ];
    }

    public static function allowedUnitsForOperationKind(string $operationKind): array
    {
        return match ($operationKind) {
            Operation::KIND_CUTTING => ['м²'],
            Operation::KIND_EDGING => ['м.п.'],
            Operation::KIND_DRILLING => ['шт.'],
            default => [],
        };
    }

    public static function allowedQuantitySourcesForOperationKind(string $operationKind): array
    {
        return match ($operationKind) {
            Operation::KIND_CUTTING => [self::QUANTITY_SOURCE_POSITION_AREA_M2],
            Operation::KIND_EDGING => [self::QUANTITY_SOURCE_EDGE_LENGTH],
            Operation::KIND_DRILLING => [self::QUANTITY_SOURCE_HOLES_COUNT],
            default => [],
        };
    }

    public static function isPricingUnitAllowedForOperationKind(?string $operationKind, ?string $pricingUnit): bool
    {
        if (!$operationKind) {
            return true;
        }

        $allowedUnits = self::allowedUnitsForOperationKind($operationKind);
        if ($allowedUnits === []) {
            return true;
        }

        $normalizedUnit = OperationPrice::normalizeUnit($pricingUnit);
        if ($normalizedUnit === null) {
            return false;
        }

        $normalizedAllowedUnits = array_map(
            fn (string $unit) => OperationPrice::normalizeUnit($unit),
            $allowedUnits
        );

        return in_array($normalizedUnit, $normalizedAllowedUnits, true);
    }

    public static function isQuantitySourceAllowedForOperationKind(?string $operationKind, ?string $quantitySource): bool
    {
        if (!$operationKind) {
            return true;
        }

        $allowedSources = self::allowedQuantitySourcesForOperationKind($operationKind);
        if ($allowedSources === []) {
            return true;
        }

        return $quantitySource !== null && in_array($quantitySource, $allowedSources, true);
    }

    public static function isValidTariffBinding(
        Operation $operation,
        ?string $tariffBindingType,
        ?int $tariffOperationId
    ): bool {
        return $tariffBindingType === self::TARIFF_BINDING_OPERATION_RESOLVER
            && $tariffOperationId !== null
            && (int) $tariffOperationId === (int) $operation->id;
    }

    public static function shouldHaveDefaultAutomaticRule(?string $operationKind): bool
    {
        return in_array($operationKind, [
            Operation::KIND_CUTTING,
            Operation::KIND_EDGING,
            Operation::KIND_DRILLING,
        ], true);
    }

    public static function defaultAutomaticRuleAttributesForOperation(Operation $operation): ?array
    {
        return match ($operation->operation_kind) {
            Operation::KIND_CUTTING => [
                'mode' => self::MODE_AUTOMATIC,
                'applies_to' => self::APPLIES_TO_MATERIAL_TYPE,
                'material_type' => Material::TYPE_PLATE,
                'material_id' => null,
                'quantity_source' => self::QUANTITY_SOURCE_POSITION_AREA_M2,
                'pricing_unit' => 'м²',
                'tariff_binding_type' => self::TARIFF_BINDING_OPERATION_RESOLVER,
                'tariff_operation_id' => $operation->id,
                'tariff_binding_json' => null,
                'conditions_json' => null,
                'quantity_config_json' => null,
                'is_enabled' => true,
            ],
            Operation::KIND_EDGING => [
                'mode' => self::MODE_AUTOMATIC,
                'applies_to' => self::APPLIES_TO_MATERIAL_TYPE,
                'material_type' => Material::TYPE_PLATE,
                'material_id' => null,
                'quantity_source' => self::QUANTITY_SOURCE_EDGE_LENGTH,
                'pricing_unit' => 'м.п.',
                'tariff_binding_type' => self::TARIFF_BINDING_OPERATION_RESOLVER,
                'tariff_operation_id' => $operation->id,
                'tariff_binding_json' => null,
                'conditions_json' => null,
                'quantity_config_json' => null,
                'is_enabled' => true,
            ],
            Operation::KIND_DRILLING => [
                'mode' => self::MODE_AUTOMATIC,
                'applies_to' => self::APPLIES_TO_MATERIAL_TYPE,
                'material_type' => Material::TYPE_PLATE,
                'material_id' => null,
                'quantity_source' => self::QUANTITY_SOURCE_HOLES_COUNT,
                'pricing_unit' => 'шт.',
                'tariff_binding_type' => self::TARIFF_BINDING_OPERATION_RESOLVER,
                'tariff_operation_id' => $operation->id,
                'tariff_binding_json' => null,
                'conditions_json' => null,
                'quantity_config_json' => null,
                'is_enabled' => true,
            ],
            default => null,
        };
    }

    protected $fillable = [
        'operation_id',
        'user_id',
        'mode',
        'applies_to',
        'material_type',
        'material_id',
        'quantity_source',
        'pricing_unit',
        'tariff_binding_type',
        'tariff_operation_id',
        'tariff_binding_json',
        'conditions_json',
        'quantity_config_json',
        'priority',
        'is_enabled',
    ];

    protected $casts = [
        'conditions_json' => 'array',
        'quantity_config_json' => 'array',
        'tariff_binding_json' => 'array',
        'priority' => 'integer',
        'is_enabled' => 'boolean',
    ];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function tariffOperation(): BelongsTo
    {
        return $this->belongsTo(Operation::class, 'tariff_operation_id');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }
}
