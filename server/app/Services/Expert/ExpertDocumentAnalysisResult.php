<?php

declare(strict_types=1);

namespace App\Services\Expert;

/** Stable per-document result contract for a future MAP stage. */
final readonly class ExpertDocumentAnalysisResult
{
    /** @param list<mixed> $facts @param list<mixed> $claims @param list<mixed> $dates @param list<mixed> $persons @param list<mixed> $amounts @param list<mixed> $normativeReferences @param list<mixed> $conclusions */
    public function __construct(
        public string $materialId,
        public string $documentType,
        public string $summary = '',
        public array $facts = [],
        public array $claims = [],
        public array $dates = [],
        public array $persons = [],
        public array $amounts = [],
        public array $normativeReferences = [],
        public array $conclusions = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'material_id' => $this->materialId,
            'document_type' => $this->documentType,
            'summary' => $this->summary,
            'facts' => $this->facts,
            'claims' => $this->claims,
            'dates' => $this->dates,
            'persons' => $this->persons,
            'amounts' => $this->amounts,
            'normative_references' => $this->normativeReferences,
            'conclusions' => $this->conclusions,
        ];
    }
}
