<?php

declare(strict_types=1);

namespace App\Services\Expert;

interface ExpertSemanticTaskClassifier
{
    /**
     * @param  array<string, mixed>  $structuralFacts
     * @param  list<string>  $deterministicSignals
     * @return array<string, mixed>|null
     */
    public function classify(string $message, array $structuralFacts, array $deterministicSignals): ?array;
}
