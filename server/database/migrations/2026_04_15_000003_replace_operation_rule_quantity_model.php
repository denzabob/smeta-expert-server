<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operation_application_rules', function (Blueprint $table) {
            $table->string('quantity_source', 40)->nullable()->after('quantity_method');
            $table->string('pricing_unit', 40)->nullable()->after('quantity_source');
            $table->string('tariff_binding_type', 40)->nullable()->default('operation_resolver')->after('pricing_unit');
            $table->foreignId('tariff_operation_id')
                ->nullable()
                ->after('tariff_binding_type')
                ->constrained('operations')
                ->nullOnDelete();
            $table->json('tariff_binding_json')->nullable()->after('tariff_operation_id');

            $table->index(['quantity_source', 'is_enabled'], 'operation_application_rules_quantity_source_idx');
            $table->index(['tariff_binding_type', 'tariff_operation_id'], 'operation_application_rules_tariff_idx');
        });

        $normalizeUnit = static function (?string $unit): string {
            if ($unit === null || trim($unit) === '') {
                return 'шт.';
            }

            $raw = mb_strtolower(trim($unit), 'UTF-8');
            $compact = str_replace([' ', "\t", "\n", "\r", '.', ',', '·'], '', $raw);

            $map = [
                'м2' => 'м²',
                'm2' => 'м²',
                'м^2' => 'м²',
                'м²' => 'м²',
                'квм' => 'м²',
                'квметр' => 'м²',
                'мп' => 'м.п.',
                'пм' => 'м.п.',
                'погм' => 'м.п.',
                'мпог' => 'м.п.',
                'шт' => 'шт.',
                'шт.' => 'шт.',
                'рез' => 'рез',
                'деталь' => 'деталь',
                'дет' => 'деталь',
                'лист' => 'лист',
            ];

            return $map[$compact] ?? $raw;
        };

        DB::table('operation_application_rules as rules')
            ->leftJoin('operations', 'operations.id', '=', 'rules.operation_id')
            ->select([
                'rules.id',
                'rules.quantity_method',
                'rules.operation_id',
                'operations.unit as operation_unit',
            ])
            ->orderBy('rules.id')
            ->get()
            ->each(function ($row) use ($normalizeUnit) {
                $isArea = $row->quantity_method === 'area_m2';

                DB::table('operation_application_rules')
                    ->where('id', $row->id)
                    ->update([
                        'quantity_source' => $isArea ? 'position_area_m2' : 'position_quantity',
                        'pricing_unit' => $isArea ? 'м²' : $normalizeUnit($row->operation_unit),
                        'tariff_binding_type' => 'operation_resolver',
                        'tariff_operation_id' => $row->operation_id,
                        'tariff_binding_json' => null,
                    ]);
            });

        Schema::table('operation_application_rules', function (Blueprint $table) {
            $table->string('quantity_method', 40)->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('operation_application_rules')
            ->whereNull('quantity_method')
            ->update([
                'quantity_method' => DB::raw("CASE WHEN quantity_source = 'position_area_m2' THEN 'area_m2' ELSE 'piece' END"),
            ]);

        Schema::table('operation_application_rules', function (Blueprint $table) {
            $table->string('quantity_method', 40)->nullable(false)->change();
        });

        Schema::table('operation_application_rules', function (Blueprint $table) {
            $table->dropForeign(['tariff_operation_id']);
            $table->dropIndex('operation_application_rules_quantity_source_idx');
            $table->dropIndex('operation_application_rules_tariff_idx');
            $table->dropColumn([
                'quantity_source',
                'pricing_unit',
                'tariff_binding_type',
                'tariff_operation_id',
                'tariff_binding_json',
            ]);
        });
    }
};
