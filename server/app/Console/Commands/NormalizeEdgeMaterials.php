<?php

namespace App\Console\Commands;

use App\Models\Material;
use App\Services\Material\EdgeMaterialNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NormalizeEdgeMaterials extends Command
{
    protected $signature = 'materials:normalize-edge
                            {--dry-run : Show the changes without writing to the database}
                            {--chunk=500 : Number of rows to process per chunk}';

    protected $description = 'Normalize existing edge materials to canonical length/thickness fields';

    public function handle(EdgeMaterialNormalizer $normalizer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(100, (int) $this->option('chunk'));

        $totals = [
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'unresolved' => 0,
        ];

        $this->info(sprintf(
            'Starting edge material normalization (%s, chunk=%d)',
            $dryRun ? 'dry-run' : 'write-mode',
            $chunkSize
        ));

        Material::query()
            ->select(['id', 'name', 'article', 'type', 'length_mm', 'width_mm', 'thickness', 'thickness_mm'])
            ->where('type', Material::TYPE_EDGE)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($materials) use ($normalizer, $dryRun, &$totals): void {
                $batch = [
                    'processed' => 0,
                    'updated' => 0,
                    'skipped' => 0,
                    'errors' => 0,
                ];

                DB::transaction(function () use ($materials, $normalizer, $dryRun, &$totals, &$batch): void {
                    foreach ($materials as $material) {
                        $batch['processed']++;
                        $totals['processed']++;

                        try {
                            $normalized = $normalizer->normalize([
                                'type' => $material->type,
                                'name' => $material->name,
                                'article' => $material->article,
                                'length_mm' => $material->length_mm,
                                'width_mm' => $material->width_mm,
                                'thickness' => $material->thickness,
                                'thickness_mm' => $material->thickness_mm,
                            ]);

                            if (($normalized['length_mm'] ?? null) === null || ($normalized['thickness'] ?? null) === null) {
                                $totals['unresolved']++;

                                Log::warning('edge_material_backfill_unresolved', [
                                    'material_id' => $material->id,
                                    'name' => $material->name,
                                    'reason' => 'no_dimensions',
                                ]);
                            }

                            $updates = $this->buildUpdates($material, $normalized);

                            if (empty($updates)) {
                                $batch['skipped']++;
                                $totals['skipped']++;
                                continue;
                            }

                            if ($dryRun) {
                                $this->line(sprintf(
                                    '[DRY-RUN] material #%d %s',
                                    $material->id,
                                    json_encode([
                                        'before' => $this->currentState($material),
                                        'after' => array_merge($this->currentState($material), $updates),
                                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                                ));
                            } else {
                                $material->update($updates);
                            }

                            $batch['updated']++;
                            $totals['updated']++;
                        } catch (\Throwable $e) {
                            $batch['errors']++;
                            $totals['errors']++;

                            Log::error('edge_material_backfill_error', [
                                'material_id' => $material->id,
                                'name' => $material->name,
                                'error' => $e->getMessage(),
                            ]);

                            $this->error(sprintf(
                                'Material #%d failed: %s',
                                $material->id,
                                $e->getMessage()
                            ));
                        }
                    }
                });

                Log::info('edge_material_backfill_batch', $batch);

                $this->info(sprintf(
                    'Batch done: processed=%d, updated=%d, skipped=%d, errors=%d',
                    $batch['processed'],
                    $batch['updated'],
                    $batch['skipped'],
                    $batch['errors']
                ));
            });

        $this->newLine();
        $this->info(sprintf(
            'Finished: processed=%d, updated=%d, skipped=%d, errors=%d, unresolved=%d',
            $totals['processed'],
            $totals['updated'],
            $totals['skipped'],
            $totals['errors'],
            $totals['unresolved']
        ));

        return $totals['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function buildUpdates(Material $material, array $normalized): array
    {
        $target = [
            'length_mm' => $normalized['length_mm'] ?? null,
            'thickness' => isset($normalized['thickness']) && $normalized['thickness'] !== null
                ? round((float) $normalized['thickness'], 2)
                : null,
            'thickness_mm' => $normalized['thickness_mm'] ?? null,
            'width_mm' => null,
        ];

        $updates = [];
        foreach ($target as $field => $value) {
            if (!$this->valuesEqual($material->{$field}, $value, $field === 'thickness')) {
                $updates[$field] = $value;
            }
        }

        return $updates;
    }

    private function currentState(Material $material): array
    {
        return [
            'length_mm' => $material->length_mm,
            'thickness' => $material->thickness !== null ? round((float) $material->thickness, 2) : null,
            'thickness_mm' => $material->thickness_mm,
            'width_mm' => $material->width_mm,
        ];
    }

    private function valuesEqual(mixed $current, mixed $target, bool $isDecimal = false): bool
    {
        if ($current === null && $target === null) {
            return true;
        }

        if ($isDecimal) {
            if ($current === null || $target === null) {
                return false;
            }

            return abs((float) $current - (float) $target) < 0.0001;
        }

        return (string) $current === (string) $target;
    }
}
