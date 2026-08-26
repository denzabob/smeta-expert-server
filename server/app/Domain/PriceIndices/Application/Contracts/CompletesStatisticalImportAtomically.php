<?php

namespace App\Domain\PriceIndices\Application\Contracts;

/**
 * Marks an importer whose import() call persists and validates the snapshot,
 * then transitions the attempt to ready_for_publish in the same transaction.
 */
interface CompletesStatisticalImportAtomically extends StatisticalSourceImporter {}
