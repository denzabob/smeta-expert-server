<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertProject;
use App\Models\Expert\ExpertProjectMaterial;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class ExpertMaterialContextBuilder
{
    public function __construct(
        private readonly ExpertMaterialTextExtractorInterface $extractor,
    ) {
    }

    /**
     * @param list<string> $publicIds
     * @return list<array{public_id: string, name: string, mime_type: string, text: string}>
     *
     * @throws ExpertMaterialContextException
     */
    public function build(ExpertProject $project, array $publicIds): array
    {
        if ($publicIds === []) {
            return [];
        }

        $requestedIds = array_values(array_unique($publicIds));
        $materialsByPublicId = $project->materials()
            ->whereIn('public_id', $requestedIds)
            ->get()
            ->keyBy('public_id');

        if ($materialsByPublicId->count() !== count($requestedIds)) {
            throw ExpertMaterialContextException::notFound();
        }

        $disk = Storage::disk('local');
        $maxMaterialBytes = max(1, (int) config('expert.material_context.max_material_bytes', 5 * 1024 * 1024));
        $maxTotalBytes = max($maxMaterialBytes, (int) config('expert.material_context.max_total_material_bytes', 10 * 1024 * 1024));
        $totalBytes = 0;

        /** @var list<array{material: ExpertProjectMaterial, bytes: int}> $resolvedMaterials */
        $resolvedMaterials = [];
        foreach ($requestedIds as $publicId) {
            /** @var ExpertProjectMaterial $material */
            $material = $materialsByPublicId->get($publicId);
            $bytes = null;

            try {
                if ($this->xlsxContextIsDisabled($material)) {
                    throw ExpertMaterialContextException::temporarilyDisabled();
                }

                if (!$this->extractor->supports($material)) {
                    throw ExpertMaterialContextException::unsupported();
                }

                if (!$disk->exists($material->storage_path)) {
                    throw ExpertMaterialContextException::extractionFailed();
                }

                $bytes = (int) $disk->size($material->storage_path);
            } catch (ExpertMaterialContextException $exception) {
                $this->logMaterialContextFailure($material, $bytes, $exception);
                throw $exception;
            } catch (\Throwable $exception) {
                $failure = ExpertMaterialContextException::extractionFailed();
                $this->logMaterialContextFailure($material, $bytes, $failure, $exception);
                throw $failure;
            }

            if ($bytes > $maxMaterialBytes) {
                throw ExpertMaterialContextException::tooLarge();
            }

            $totalBytes += $bytes;
            if ($totalBytes > $maxTotalBytes) {
                throw ExpertMaterialContextException::tooLarge();
            }

            $resolvedMaterials[] = ['material' => $material, 'bytes' => $bytes];
        }

        $maxCharsPerMaterial = max(1, (int) config('expert.material_context.max_extracted_chars_per_material', 30000));
        $maxTotalChars = max($maxCharsPerMaterial, (int) config('expert.material_context.max_total_extracted_chars', 80000));
        $totalChars = 0;
        $context = [];

        foreach ($resolvedMaterials as $resolved) {
            $material = $resolved['material'];

            try {
                $contents = $disk->get($material->storage_path);
                $text = $this->extractor->extract(
                    $material,
                    $contents,
                    $disk->path($material->storage_path),
                );
            } catch (ExpertMaterialContextException $exception) {
                $this->logMaterialContextFailure($material, $resolved['bytes'], $exception);
                throw $exception;
            } catch (\Throwable $exception) {
                $failure = ExpertMaterialContextException::extractionFailed();
                $this->logMaterialContextFailure($material, $resolved['bytes'], $failure, $exception);
                throw $failure;
            }

            $chars = mb_strlen($text, 'UTF-8');
            if ($chars > $maxCharsPerMaterial) {
                $failure = ExpertMaterialContextException::tooLarge();
                $this->logMaterialContextFailure($material, $resolved['bytes'], $failure);
                throw $failure;
            }

            $totalChars += $chars;
            if ($totalChars > $maxTotalChars) {
                $failure = ExpertMaterialContextException::tooLarge();
                $this->logMaterialContextFailure($material, $resolved['bytes'], $failure);
                throw $failure;
            }

            $context[] = [
                'public_id' => (string) $material->public_id,
                'name' => $this->presentationName($material),
                'mime_type' => (string) $material->mime_type,
                'text' => $text,
            ];
        }

        return $context;
    }

    private function xlsxContextIsDisabled(ExpertProjectMaterial $material): bool
    {
        return strtolower(ltrim(trim((string) $material->extension), '.')) === 'xlsx'
            && !(bool) config('expert.material_context.xlsx_enabled', false);
    }

    private function logMaterialContextFailure(
        ExpertProjectMaterial $material,
        ?int $bytes,
        ExpertMaterialContextException $failure,
        ?\Throwable $cause = null,
    ): void {
        Log::warning('Expert material context rejected.', array_filter([
            'material_public_id' => (string) $material->public_id,
            'mime_type' => (string) $material->mime_type,
            'extension' => strtolower(ltrim(trim((string) $material->extension), '.')),
            'bytes' => $bytes,
            'error_code' => $failure->errorCode,
            'cause' => $cause ? $cause::class : null,
        ], static fn (mixed $value): bool => $value !== null));
    }

    private function presentationName(ExpertProjectMaterial $material): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $material->original_name);
        $name = is_string($name) ? trim($name) : '';

        if ($name !== '') {
            return $name;
        }

        $extension = strtolower(ltrim(trim((string) $material->extension), '.'));

        return 'Материал без названия' . ($extension === '' ? '' : '.' . $extension);
    }
}
