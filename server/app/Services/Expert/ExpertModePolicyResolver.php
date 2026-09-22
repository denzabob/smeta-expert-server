<?php

declare(strict_types=1);

namespace App\Services\Expert;

final class ExpertModePolicyResolver
{
    public function resolve(string $requestedMode, string $message, ExpertTaskRequirements $requirements): ExpertModeResolution
    {
        $requestedMode = ExpertModeResolution::normalise($requestedMode);
        if ($requestedMode === ExpertModeResolution::FAST) {
            return new ExpertModeResolution($requestedMode, ExpertModeResolution::FAST, 'explicit_fast');
        }
        if ($requestedMode === ExpertModeResolution::DEEP) {
            return new ExpertModeResolution($requestedMode, ExpertModeResolution::DEEP, 'explicit_deep');
        }

        $resolved = $this->isDeep($requirements) ? ExpertModeResolution::DEEP : ExpertModeResolution::FAST;

        return new ExpertModeResolution($requestedMode, $resolved, $this->reason($requirements, $resolved));
    }

    private function isDeep(ExpertTaskRequirements $requirements): bool
    {
        if ($requirements->scope === 'exhaustive_multi' || ($requirements->requiresExhaustiveCoverage && $requirements->materialCount > 1)) {
            return true;
        }
        if ($requirements->hasExplicitComparison && $requirements->materialCount > 1) {
            return true;
        }
        if ($requirements->requiresRetrievalPipeline || $requirements->requiresMultiDocumentPipeline) {
            return true;
        }
        if ($requirements->requiresReasoning && $requirements->materialCount > 1) {
            return true;
        }

        return $requirements->requiresReasoning && $requirements->materialCount >= 1;
    }

    private function reason(ExpertTaskRequirements $requirements, string $resolved): string
    {
        if ($resolved === ExpertModeResolution::DEEP) {
            if ($requirements->scope === 'exhaustive_multi' || ($requirements->requiresExhaustiveCoverage && $requirements->materialCount > 1)) {
                return 'auto_exhaustive_analysis';
            }
            if ($requirements->hasExplicitComparison && $requirements->materialCount > 1) {
                return 'auto_multi_document_reasoning';
            }
            if ($requirements->requiresRetrievalPipeline) {
                return 'auto_retrieval_analysis';
            }
            if ($requirements->requiresMultiDocumentPipeline) {
                return 'auto_multi_document_workload';
            }
            if ($requirements->requiresReasoning && $requirements->materialCount > 1) {
                return 'auto_targeted_comparison';
            }

            return 'auto_complex_expert_task';
        }
        if ($requirements->requiresVision) {
            return 'auto_vision_qa';
        }
        if ($requirements->materialCount === 1) {
            return $requirements->requiresExhaustiveCoverage ? 'auto_fact_extraction' : 'auto_single_document_qa';
        }

        return 'auto_simple_chat';
    }
}
