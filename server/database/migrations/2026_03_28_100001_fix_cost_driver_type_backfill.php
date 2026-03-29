<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrective migration: fixes the overly simplistic backfill from
 * 2026_03_28_000001 which classified all non-fitting items as 'plate',
 * missing 'edge' and 'facade' cost driver types.
 *
 * Uses a tiered repair strategy:
 *   1. fitting  — project_fitting_id IS NOT NULL
 *   2. edge     — rri.material_id = pp.edge_material_id
 *   3. facade   — rri.material_id = pp.facade_material_id
 *   4. plate    — rri.material_id = pp.material_id
 *   5. edge     — materials.type = 'edge'   (orphan FK fallback)
 *   6. facade   — materials.type = 'facade' (orphan FK fallback)
 *   7. plate    — catch-all default
 *
 * Each step is idempotent: rows already carrying the correct value are skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tier 1: fittings (re-assert for idempotency)
        DB::table('revision_run_items')
            ->whereNotNull('project_fitting_id')
            ->where('cost_driver_type', '!=', 'fitting')
            ->update(['cost_driver_type' => 'fitting']);

        // Tier 2: edge — material matches position's edge FK
        DB::statement("
            UPDATE revision_run_items rri
            JOIN project_positions pp ON pp.id = rri.project_position_id
            SET rri.cost_driver_type = 'edge'
            WHERE rri.project_position_id IS NOT NULL
              AND pp.edge_material_id IS NOT NULL
              AND rri.material_id = pp.edge_material_id
              AND rri.cost_driver_type != 'edge'
        ");

        // Tier 3: facade — material matches position's facade FK
        DB::statement("
            UPDATE revision_run_items rri
            JOIN project_positions pp ON pp.id = rri.project_position_id
            SET rri.cost_driver_type = 'facade'
            WHERE rri.project_position_id IS NOT NULL
              AND pp.facade_material_id IS NOT NULL
              AND rri.material_id = pp.facade_material_id
              AND rri.cost_driver_type != 'facade'
        ");

        // Tier 4: plate — material matches position's plate FK (confirm correct)
        DB::statement("
            UPDATE revision_run_items rri
            JOIN project_positions pp ON pp.id = rri.project_position_id
            SET rri.cost_driver_type = 'plate'
            WHERE rri.project_position_id IS NOT NULL
              AND pp.material_id IS NOT NULL
              AND rri.material_id = pp.material_id
              AND rri.cost_driver_type != 'plate'
        ");

        // Tier 5: fallback for orphans — position material FK changed after run creation.
        // Use materials.type as authoritative source.
        DB::statement("
            UPDATE revision_run_items rri
            JOIN materials m ON m.id = rri.material_id
            SET rri.cost_driver_type = 'edge'
            WHERE m.type = 'edge'
              AND rri.cost_driver_type != 'edge'
              AND rri.project_fitting_id IS NULL
              AND (
                  rri.project_position_id IS NULL
                  OR rri.material_id NOT IN (
                      SELECT pp2.material_id FROM project_positions pp2 WHERE pp2.id = rri.project_position_id AND pp2.material_id IS NOT NULL
                      UNION
                      SELECT pp3.edge_material_id FROM project_positions pp3 WHERE pp3.id = rri.project_position_id AND pp3.edge_material_id IS NOT NULL
                      UNION
                      SELECT pp4.facade_material_id FROM project_positions pp4 WHERE pp4.id = rri.project_position_id AND pp4.facade_material_id IS NOT NULL
                  )
              )
        ");

        DB::statement("
            UPDATE revision_run_items rri
            JOIN materials m ON m.id = rri.material_id
            SET rri.cost_driver_type = 'facade'
            WHERE m.type = 'facade'
              AND rri.cost_driver_type != 'facade'
              AND rri.project_fitting_id IS NULL
              AND (
                  rri.project_position_id IS NULL
                  OR rri.material_id NOT IN (
                      SELECT pp2.material_id FROM project_positions pp2 WHERE pp2.id = rri.project_position_id AND pp2.material_id IS NOT NULL
                      UNION
                      SELECT pp3.edge_material_id FROM project_positions pp3 WHERE pp3.id = rri.project_position_id AND pp3.edge_material_id IS NOT NULL
                      UNION
                      SELECT pp4.facade_material_id FROM project_positions pp4 WHERE pp4.id = rri.project_position_id AND pp4.facade_material_id IS NOT NULL
                  )
              )
        ");

        // Tier 7: any remaining NULLs → 'plate'
        DB::table('revision_run_items')
            ->whereNull('cost_driver_type')
            ->update(['cost_driver_type' => 'plate']);
    }

    /**
     * Rollback cannot safely restore the prior misclassified values.
     * The original backfill was wrong — reverting to wrong data has no value.
     * down() re-applies the naive fitting/plate backfill from the original migration,
     * which is the only deterministic reverse operation.
     */
    public function down(): void
    {
        // Reset all to NULL, then re-apply the original naive logic
        DB::table('revision_run_items')->update(['cost_driver_type' => null]);

        DB::table('revision_run_items')
            ->whereNotNull('project_fitting_id')
            ->update(['cost_driver_type' => 'fitting']);

        DB::table('revision_run_items')
            ->whereNull('cost_driver_type')
            ->update(['cost_driver_type' => 'plate']);
    }
};
