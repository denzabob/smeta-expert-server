<?php

declare(strict_types=1);

namespace App\Services\Expert;

final class NullExpertSemanticTaskClassifier implements ExpertSemanticTaskClassifier
{
    public function classify(string $message, array $structuralFacts, array $deterministicSignals): ?array
    {
        return null;
    }
}
