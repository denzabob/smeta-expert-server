<?php

namespace App\Console\Commands;

use App\Models\OperationPrice;
use Illuminate\Console\Command;

class AuditOperationPricesCommand extends Command
{
    protected $signature = 'operations:audit-prices
        {--version-id= : Only audit a single price_list_version_id}
        {--fix-units : Normalize source_unit in DB (safe canonicalization only)}
        {--unlink-suspicious : Set operation_id=NULL for suspiciously mapped rows}
        {--limit=50 : Max suspicious rows to print}';

    protected $description = 'Audit operation_prices for unit mismatches and suspicious manual mappings';

    public function handle(): int
    {
        $versionId = $this->option('version-id');
        $fixUnits = (bool) $this->option('fix-units');
        $unlinkSuspicious = (bool) $this->option('unlink-suspicious');
        $limit = max(1, (int) $this->option('limit'));

        $query = OperationPrice::query()
            ->with('operation')
            ->whereNotNull('operation_id');

        if ($versionId) {
            $query->where('price_list_version_id', (int) $versionId);
        }

        $rows = $query->orderByDesc('id')->get();
        if ($rows->isEmpty()) {
            $this->warn('No linked operation_prices found.');
            return self::SUCCESS;
        }

        $unitMismatch = [];
        $semanticMismatch = [];
        $normalizedUnitsUpdated = 0;
        $suspiciousUnlinked = 0;

        foreach ($rows as $row) {
            $operation = $row->operation;
            if (!$operation) {
                continue;
            }

            $normalizedSource = OperationPrice::normalizeUnit($row->source_unit);
            $normalizedOpUnit = OperationPrice::normalizeUnit($operation->unit);

            // Safe canonicalization only (e.g. "м2" -> "м²", "шт" -> "шт.")
            if ($fixUnits && $row->source_unit !== null && $normalizedSource !== null && $row->source_unit !== $normalizedSource) {
                $row->source_unit = $normalizedSource;
                $row->save();
                $normalizedUnitsUpdated++;
            }

            if ($row->source_unit !== null && $row->conversion_factor == 1.0 && $normalizedSource !== $normalizedOpUnit) {
                $unitMismatch[] = $row;
            }

            if ($this->looksSemanticallyMismatched($row->source_name ?? '', $operation->name ?? '')) {
                $semanticMismatch[] = $row;

                if ($unlinkSuspicious) {
                    $row->operation_id = null;
                    $row->match_confidence = null;
                    $row->save();
                    $suspiciousUnlinked++;
                }
            }
        }

        $this->info("Rows scanned: {$rows->count()}");
        $this->line("Unit mismatches (conversion_factor=1): " . count($unitMismatch));
        $this->line("Suspicious semantic mappings: " . count($semanticMismatch));
        if ($fixUnits) {
            $this->line("Normalized units updated: {$normalizedUnitsUpdated}");
        }
        if ($unlinkSuspicious) {
            $this->line("Suspicious rows unlinked: {$suspiciousUnlinked}");
        }

        if (!empty($unitMismatch)) {
            $this->newLine();
            $this->warn('Top unit mismatch rows:');
            $this->table(
                ['id', 'version', 'operation_id', 'operation', 'source_unit', 'operation_unit', 'source_name'],
                collect($unitMismatch)->take($limit)->map(function ($r) {
                    return [
                        $r->id,
                        $r->price_list_version_id,
                        $r->operation_id,
                        $r->operation?->name,
                        $r->source_unit,
                        $r->operation?->unit,
                        $r->source_name,
                    ];
                })->all()
            );
        }

        if (!empty($semanticMismatch)) {
            $this->newLine();
            $this->warn('Top suspicious semantic mapping rows:');
            $this->table(
                ['id', 'version', 'operation_id', 'operation', 'source_name', 'match_confidence'],
                collect($semanticMismatch)->take($limit)->map(function ($r) {
                    return [
                        $r->id,
                        $r->price_list_version_id,
                        $r->operation_id,
                        $r->operation?->name,
                        $r->source_name,
                        $r->match_confidence,
                    ];
                })->all()
            );
        }

        return self::SUCCESS;
    }

    private function looksSemanticallyMismatched(string $sourceName, string $operationName): bool
    {
        $source = mb_strtolower($sourceName, 'UTF-8');
        $target = mb_strtolower($operationName, 'UTF-8');
        if ($source === '' || $target === '') {
            return false;
        }

        $markers = ['распил', 'кромкооблицов', 'криволин', 'прямолин', 'глян', 'покрыт'];
        foreach ($markers as $marker) {
            $sourceHas = mb_strpos($source, $marker) !== false;
            $targetHas = mb_strpos($target, $marker) !== false;
            if ($sourceHas !== $targetHas) {
                return true;
            }
        }

        $sourceDim = $this->extractDimToken($sourceName);
        $targetDim = $this->extractDimToken($operationName);
        if ($sourceDim !== null && $targetDim !== null && $sourceDim !== $targetDim) {
            return true;
        }

        return false;
    }

    private function extractDimToken(string $value): ?string
    {
        if (!preg_match('/\b(\d+(?:[.,]\d+)?)\s*[xх]\s*(\d+(?:[.,]\d+)?)\b/u', $value, $m)) {
            return null;
        }

        $a = str_replace(',', '.', $m[1]);
        $b = str_replace(',', '.', $m[2]);
        return "{$a}x{$b}";
    }
}
