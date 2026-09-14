<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Services\LLM\DTO\LLMImageContent;
use App\Services\LLM\DTO\LLMFileContent;

final class ExpertChatMaterialContext
{
    /**
     * @param  list<array{public_id: string, name: string, mime_type: string, text: string}>  $textMaterials
     * @param  list<LLMImageContent>  $images
     */
    public function __construct(
        public readonly array $textMaterials,
        public readonly array $images,
        /** @var list<LLMFileContent> */
        public readonly array $files = [],
        /** @var list<ExpertPdfOcrCandidate> */
        public readonly array $ocrCandidates = [],
    ) {}
}
