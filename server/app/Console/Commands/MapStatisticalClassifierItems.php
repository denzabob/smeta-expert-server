<?php

namespace App\Console\Commands;

use App\Domain\PriceIndices\Application\Services\GenerateClassifierItemMappings;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierItemMappingException;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

class MapStatisticalClassifierItems extends Command
{
    protected $signature = 'price-indices:classifier:map {classifier : Canonical classifier code}';

    protected $description = 'Generate deterministic version-scoped mappings for compatible dataset-local classifier items';

    public function handle(GenerateClassifierItemMappings $generator): int
    {
        try {
            $result = $generator->execute((string) $this->argument('classifier'));
        } catch (ClassifierItemMappingException $exception) {
            $this->components->error("[{$exception->errorCode}] {$exception->safeMessage}");

            return SymfonyCommand::FAILURE;
        } catch (Throwable) {
            $this->components->error('[classifier_mapping_failure] Classifier mappings could not be generated safely.');

            return SymfonyCommand::FAILURE;
        }

        $this->table(['Field', 'Value'], [
            ['classifier', $result->classifierCode],
            ['active_version_public_id', $result->activeVersionPublicId],
            ['active_version_label', $result->activeVersionLabel],
            ['total_compatible_local_items', (string) $result->totalCompatibleItems],
            ['exact_confirmed', (string) $result->exactConfirmed],
            ['ambiguous_needs_review', (string) $result->ambiguousNeedsReview],
            ['local_rosstat', (string) $result->localRosstat],
            ['unmapped', (string) $result->unmapped],
            ['manual_preserved', (string) $result->manualPreserved],
        ]);

        return SymfonyCommand::SUCCESS;
    }
}
