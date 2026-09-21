<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Services\LLM\Enums\LLMCapability;
use App\Services\LLM\Enums\LLMFileProcessingIntent;

final class ExpertToolPolicyResolver
{
    public function resolve(ExpertTaskRequirements $requirements, ExpertChatMaterialContextBundle $bundle): ExpertToolPlan
    {
        $tools = [];
        $capabilities = [LLMCapability::TEXT_INPUT->value];

        if ($bundle->combined()->textMaterials !== []) {
            $tools[] = ExpertToolPlan::LOCAL_TEXT_PARSE;
        }
        foreach ($bundle->combined()->ocrCandidates as $candidate) {
            $tool = $candidate->processingIntent === LLMFileProcessingIntent::PDF_TEXT_PARSE
                ? ExpertToolPlan::PDF_TEXT_PARSE
                : ExpertToolPlan::PDF_OCR;
            if (! in_array($tool, $tools, true)) {
                $tools[] = $tool;
            }
        }
        if ($requirements->requiresPdfProcessing) {
            $capabilities[] = $requirements->hasScannedPdf
                ? LLMCapability::PDF_OCR->value
                : LLMCapability::FILE_INPUT->value;
        }
        if ($requirements->requiresVision) {
            $tools[] = ExpertToolPlan::VISION;
            $capabilities[] = LLMCapability::IMAGE_INPUT->value;
        }

        return new ExpertToolPlan(
            array_values(array_unique($tools)),
            array_values(array_unique($capabilities)),
        );
    }
}
