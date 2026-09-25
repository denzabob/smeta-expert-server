<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Services\LLM\DTO\LLMFileContent;
use App\Services\LLM\DTO\LLMImageContent;

final class ExpertEvidenceContextValidator
{
    /** @return list<array{id: string, role: string, name: string, mime_type: string, text: ?string, images: list<LLMImageContent>, files: list<LLMFileContent>}> */
    public function prepare(ExpertChatMaterialContextBundle $bundle): array
    {
        $context = $bundle->combined();
        $selected = $bundle->plan?->resolution?->selected;
        if ($selected === null && $bundle->plan !== null) {
            $selected = array_map(static fn (string $id): array => ['material_id' => $id, 'role' => 'primary'], $bundle->plan->resolvedMaterials);
        }
        if ($selected === null) {
            $ids = [
                ...array_column($context->textMaterials, 'public_id'),
                ...array_map(static fn (LLMImageContent $image): string => $image->materialPublicId, $context->images),
                ...array_map(static fn (ExpertPdfOcrCandidate $candidate): string => $candidate->materialPublicId, $context->ocrCandidates),
            ];
            $selected = array_map(static fn (string $id): array => ['material_id' => $id, 'role' => 'primary'], array_values(array_unique($ids)));
        }

        $sources = [];
        foreach ($selected as $source) {
            $id = $source['material_id'] ?? null;
            $role = $source['role'] ?? null;
            if (! is_string($id) || $id === '' || ! in_array($role, ['primary', 'comparison', 'supporting'], true) || isset($sources[$id])) {
                throw ExpertMaterialContextException::evidenceMismatch();
            }
            $sources[$id] = ['id' => $id, 'role' => $role, 'name' => '', 'mime_type' => '', 'text' => null, 'images' => [], 'files' => []];
        }

        foreach ($context->textMaterials as $material) {
            $id = $material['public_id'] ?? null;
            if (! is_string($id) || ! isset($sources[$id]) || $sources[$id]['text'] !== null) {
                throw ExpertMaterialContextException::evidenceMismatch();
            }
            $this->describe($sources[$id], (string) ($material['name'] ?? ''), (string) ($material['mime_type'] ?? ''));
            $sources[$id]['text'] = (string) ($material['text'] ?? '');
        }
        foreach ($context->images as $image) {
            if (! isset($sources[$image->materialPublicId])) {
                throw ExpertMaterialContextException::evidenceMismatch();
            }
            $this->describe($sources[$image->materialPublicId], $image->name, $image->mimeType);
            $sources[$image->materialPublicId]['images'][] = $image;
        }

        $candidates = [];
        foreach ($context->ocrCandidates as $candidate) {
            $hash = strtolower($candidate->sha256);
            if (! isset($sources[$candidate->materialPublicId]) || isset($candidates[$hash])) {
                throw ExpertMaterialContextException::evidenceMismatch();
            }
            $candidates[$hash] = $candidate;
        }
        foreach ($context->files as $file) {
            $hash = strtolower($file->sha256);
            $candidate = $candidates[$hash] ?? null;
            if ($candidate === null || $candidate->name !== $file->filename || $candidate->mimeType !== $file->mimeType) {
                throw ExpertMaterialContextException::evidenceMismatch();
            }
            $this->describe($sources[$candidate->materialPublicId], $candidate->name, $candidate->mimeType);
            $sources[$candidate->materialPublicId]['files'][] = $file;
            unset($candidates[$hash]);
        }
        if ($candidates !== []) {
            throw ExpertMaterialContextException::evidenceMismatch();
        }
        foreach ($sources as $source) {
            if ($source['name'] === '' || ($source['text'] === null && $source['images'] === [] && $source['files'] === [])) {
                throw ExpertMaterialContextException::evidenceMismatch();
            }
        }

        return array_values($sources);
    }

    /** @param array<string, mixed> $source */
    private function describe(array &$source, string $name, string $mimeType): void
    {
        if ($name === '' || $mimeType === '' || ($source['name'] !== '' && $source['name'] !== $name)) {
            throw ExpertMaterialContextException::evidenceMismatch();
        }
        $source['name'] = $name;
        $source['mime_type'] = $source['mime_type'] === '' ? $mimeType : $source['mime_type'];
    }
}
