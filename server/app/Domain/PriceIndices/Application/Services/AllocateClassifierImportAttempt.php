<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\ClassifierImportAllocation;
use App\Domain\PriceIndices\Application\Data\TrustedClassifierCandidateDescriptor;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifier;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierImport;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierSourceFile;
use App\Domain\PriceIndices\Domain\Enums\ClassifierImportStatus;
use Illuminate\Support\Facades\DB;

class AllocateClassifierImportAttempt
{
    public function __construct(private readonly FindEquivalentReadyClassifierImport $readyImports) {}

    public function allocate(
        TrustedClassifierCandidateDescriptor $descriptor,
        StatisticalClassifier $classifier,
        StatisticalClassifierSourceFile $source,
    ): ClassifierImportAllocation {
        return DB::transaction(function () use ($descriptor, $classifier, $source): ClassifierImportAllocation {
            $lockedSource = StatisticalClassifierSourceFile::query()
                ->whereKey($source->id)
                ->where('classifier_id', $classifier->id)
                ->lockForUpdate()
                ->firstOrFail();

            $ready = $this->readyImports->find($descriptor, $lockedSource);

            if ($ready !== null) {
                return new ClassifierImportAllocation($ready, true);
            }

            $lastAttempt = StatisticalClassifierImport::query()
                ->where('source_file_id', $lockedSource->id)
                ->max('attempt');

            $import = StatisticalClassifierImport::query()->create([
                'classifier_id' => $classifier->id,
                'source_file_id' => $lockedSource->id,
                'attempt' => ((int) $lastAttempt) + 1,
                'status' => ClassifierImportStatus::Pending,
                'parser_code' => $descriptor->parserCode,
                'parser_version' => (string) $descriptor->parserVersion,
                'validation_errors_count' => 0,
                'validation_warnings_count' => 0,
            ]);

            return new ClassifierImportAllocation($import, false);
        }, 3);
    }
}
