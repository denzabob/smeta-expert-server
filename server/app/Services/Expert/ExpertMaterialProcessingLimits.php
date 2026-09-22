<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertProjectMaterial;

/**
 * Resolves infrastructure limits for one material type.
 *
 * Source limits protect storage/parsing, while context limits are evaluated by
 * the workload planner. In particular, PDF source bytes are not subject to
 * the generic text-material 5/10 MiB limits.
 */
final class ExpertMaterialProcessingLimits
{
    /**
     * @return array{
     *     kind: string,
     *     max_source_bytes: int,
     *     max_total_source_bytes: int,
     *     max_total_prepared_payload_bytes: int,
     *     max_extracted_chars: int,
     *     max_total_extracted_chars: int,
     *     max_documents: int,
     *     max_pages_per_material: int,
     *     max_total_pages: int,
     *     max_prepared_payload_bytes: int,
     *     max_width: int,
     *     max_height: int,
     *     max_pixels: int,
     *     max_output_width: int,
     *     max_output_height: int,
     *     jpeg_quality: int,
     * }
     */
    public function resolve(ExpertProjectMaterial $material): array
    {
        $kind = $this->kind($material);

        if ($kind === 'pdf') {
            return [
                'kind' => $kind,
                'max_source_bytes' => $this->positiveConfig('expert.pdf_ocr.max_source_bytes', 20 * 1024 * 1024),
                'max_total_source_bytes' => 0,
                'max_total_prepared_payload_bytes' => 0,
                'max_extracted_chars' => $this->positiveConfig('expert.pdf_ocr.max_annotation_text_chars', 500000),
                'max_total_extracted_chars' => 0,
                'max_documents' => $this->positiveConfig('expert.pdf_ocr.max_pdfs', 2),
                'max_pages_per_material' => $this->positiveConfig('expert.pdf_ocr.max_pages_per_pdf', 100),
                'max_total_pages' => $this->positiveConfig('expert.pdf_ocr.max_total_pages', 150),
                'max_prepared_payload_bytes' => $this->positiveConfig('expert.pdf_ocr.max_base64_request_bytes', 56 * 1024 * 1024),
                'max_width' => 0,
                'max_height' => 0,
                'max_pixels' => 0,
                'max_output_width' => 0,
                'max_output_height' => 0,
                'jpeg_quality' => 0,
            ];
        }

        if ($kind === 'image') {
            return [
                'kind' => $kind,
                'max_source_bytes' => $this->positiveConfig('expert.vision.max_source_bytes', 15 * 1024 * 1024),
                'max_total_source_bytes' => $this->positiveConfig('expert.vision.max_total_vision_bytes', 12 * 1024 * 1024),
                'max_total_prepared_payload_bytes' => $this->positiveConfig('expert.vision.max_total_vision_bytes', 12 * 1024 * 1024),
                'max_extracted_chars' => 0,
                'max_total_extracted_chars' => 0,
                'max_documents' => 0,
                'max_pages_per_material' => 0,
                'max_total_pages' => 0,
                'max_prepared_payload_bytes' => $this->positiveConfig('expert.vision.max_prepared_image_bytes', 5 * 1024 * 1024),
                'max_width' => $this->positiveConfig('expert.vision.max_width', 10000),
                'max_height' => $this->positiveConfig('expert.vision.max_height', 10000),
                'max_pixels' => $this->positiveConfig('expert.vision.max_pixels', 25_000_000),
                'max_output_width' => $this->positiveConfig('expert.vision.max_output_width', 2048),
                'max_output_height' => $this->positiveConfig('expert.vision.max_output_height', 2048),
                'jpeg_quality' => $this->positiveConfig('expert.vision.jpeg_quality', 88),
            ];
        }

        return [
            'kind' => $kind,
            'max_source_bytes' => $this->positiveConfig('expert.material_context.max_material_bytes', 5 * 1024 * 1024),
            'max_total_source_bytes' => $this->positiveConfig('expert.material_context.max_total_material_bytes', 10 * 1024 * 1024),
            'max_total_prepared_payload_bytes' => 0,
            'max_extracted_chars' => $this->positiveConfig('expert.material_context.max_extracted_chars_per_material', 30000),
            'max_total_extracted_chars' => $this->positiveConfig('expert.material_context.max_total_extracted_chars', 80000),
            'max_documents' => 0,
            'max_pages_per_material' => 0,
            'max_total_pages' => 0,
            'max_prepared_payload_bytes' => 0,
            'max_width' => 0,
            'max_height' => 0,
            'max_pixels' => 0,
            'max_output_width' => 0,
            'max_output_height' => 0,
            'jpeg_quality' => 0,
        ];
    }

    public function kind(ExpertProjectMaterial $material): string
    {
        $extension = strtolower(ltrim(trim((string) $material->extension), '.'));
        $mimeType = strtolower(trim((string) $material->mime_type));

        if ($extension === 'pdf' || $mimeType === 'application/pdf') {
            return 'pdf';
        }

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'], true) || str_starts_with($mimeType, 'image/')) {
            return 'image';
        }

        return match (true) {
            in_array($extension, ['doc', 'docx'], true) => 'docx',
            in_array($extension, ['xls', 'xlsx'], true) => 'xlsx',
            in_array($extension, ['txt', 'md'], true) || in_array($mimeType, ['text/plain', 'text/markdown'], true) => 'text',
            default => 'other',
        };
    }

    /** @return array<string, int|string> */
    public function resolvePdf(): array
    {
        $material = new ExpertProjectMaterial;
        $material->extension = 'pdf';
        $material->mime_type = 'application/pdf';

        return $this->resolve($material);
    }

    private function positiveConfig(string $key, int $fallback): int
    {
        return max(1, (int) config($key, $fallback));
    }
}
