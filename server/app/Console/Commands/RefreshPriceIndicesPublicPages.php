<?php

namespace App\Console\Commands;

use App\Domain\PriceIndices\Application\Services\RefreshPublicStatisticalSeriesPages;
use Illuminate\Console\Command;
use InvalidArgumentException;

class RefreshPriceIndicesPublicPages extends Command
{
    protected $signature = 'price-indices:refresh-public-pages
        {--dataset= : Enabled dataset code or public UUID}
        {--series= : Series public UUID or classifier item code}
        {--limit= : Maximum number of series across selected datasets}
        {--dry-run : Calculate and report without writing snapshots}';

    protected $description = 'Refresh materialized public Price Indices series snapshots from active publications';

    public function handle(RefreshPublicStatisticalSeriesPages $refresh): int
    {
        $limit = $this->option('limit');
        if ($limit !== null && preg_match('/^[1-9]\d*$/D', (string) $limit) !== 1) {
            $this->error('--limit must be a positive integer.');

            return self::FAILURE;
        }

        try {
            $result = $refresh->execute(
                $this->stringOption('dataset'),
                $this->stringOption('series'),
                $limit === null ? null : (int) $limit,
                (bool) $this->option('dry-run'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($result->datasets as $dataset) {
            $this->line("Dataset: {$dataset['dataset']}");
            $this->line("Active import: {$dataset['active_import']}");
        }
        $this->line("Series scanned: {$result->seriesScanned}");
        $this->line("Indexable: {$result->indexable}");
        $this->line("Non-indexable: {$result->nonIndexable}");
        $this->line("Created: {$result->created}");
        $this->line("Updated: {$result->updated}");
        $this->line("Unchanged: {$result->unchanged}");
        $this->line("Failed: {$result->failed}");
        $this->line("Stale: {$result->stale}");
        $this->line('Dry run: '.($result->dryRun ? 'yes' : 'no'));

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = trim((string) $this->option($name));

        return $value === '' ? null : $value;
    }
}
