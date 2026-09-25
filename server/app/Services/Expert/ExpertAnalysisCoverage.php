<?php

declare(strict_types=1);

namespace App\Services\Expert;

final readonly class ExpertAnalysisCoverage
{
    /** @param list<ExpertAnalysisCoverageItem> $materials */
    public function __construct(
        public int $requested,
        public int $processed,
        public int $failed,
        public int $skipped,
        public bool $complete,
        public array $materials,
    ) {}

    public static function fromBundle(ExpertContextPack $pack, ExpertChatMaterialContextBundle $bundle): self
    {
        $details = [];
        foreach ($bundle->combined()->textMaterials as $material) {
            $id = (string) ($material['public_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $details[$id] = [
                'name' => self::safeName((string) ($material['name'] ?? '')),
                'strategy' => (string) ($material['processing_strategy'] ?? 'local_text'),
                'status' => 'processed',
            ];
        }
        foreach ($bundle->combined()->images as $image) {
            $details[$image->materialPublicId] = [
                'name' => self::safeName($image->name),
                'strategy' => 'vision',
                'status' => 'processed',
            ];
        }
        foreach ($bundle->combined()->ocrCandidates as $candidate) {
            $details[$candidate->materialPublicId] = [
                'name' => self::safeName($candidate->name),
                'strategy' => $candidate->processingStrategy(),
                'status' => 'processing',
            ];
        }

        $items = [];
        foreach ($pack->resolution?->selected === null ? $pack->resolvedMaterials : array_column($pack->resolution->selected, 'material_id') as $materialId) {
            $detail = $details[(string) $materialId] ?? null;
            $items[] = new ExpertAnalysisCoverageItem(
                (string) $materialId,
                $detail['name'] ?? 'Материал без названия',
                $detail['status'] ?? 'pending',
                $detail['strategy'] ?? 'pending',
            );
        }

        return self::fromItems($items);
    }

    public function markAllProcessed(): self
    {
        return $this->mapItems(static fn (ExpertAnalysisCoverageItem $item): ExpertAnalysisCoverageItem => new ExpertAnalysisCoverageItem(
            $item->materialId,
            $item->name,
            'processed',
            $item->processingStrategy,
            null,
        ));
    }

    public function markFailed(string $materialId, string $errorCode, ?string $strategy = null): self
    {
        return $this->update($materialId, 'failed', $strategy, $errorCode);
    }

    public function markSkipped(string $materialId, ?string $strategy = null): self
    {
        return $this->update($materialId, 'skipped', $strategy, null);
    }

    /** @return array<string, mixed> */
    public function toMetadata(): array
    {
        return [
            'coverage_requested' => $this->requested,
            'coverage_processed' => $this->processed,
            'coverage_failed' => $this->failed,
            'coverage_skipped' => $this->skipped,
            'coverage_complete' => $this->complete,
            'coverage_manifest' => array_map(static fn (ExpertAnalysisCoverageItem $item): array => $item->toArray(), $this->materials),
        ];
    }

    /** @param list<ExpertAnalysisCoverageItem> $items */
    private static function fromItems(array $items): self
    {
        $processed = count(array_filter($items, static fn (ExpertAnalysisCoverageItem $item): bool => $item->status === 'processed'));
        $failed = count(array_filter($items, static fn (ExpertAnalysisCoverageItem $item): bool => $item->status === 'failed'));
        $skipped = count(array_filter($items, static fn (ExpertAnalysisCoverageItem $item): bool => $item->status === 'skipped'));

        return new self(
            requested: count($items),
            processed: $processed,
            failed: $failed,
            skipped: $skipped,
            complete: $processed === count($items) && $failed === 0 && $skipped === 0,
            materials: array_values($items),
        );
    }

    /** @param callable(ExpertAnalysisCoverageItem): ExpertAnalysisCoverageItem $callback */
    private function mapItems(callable $callback): self
    {
        return self::fromItems(array_map($callback, $this->materials));
    }

    private function update(string $materialId, string $status, ?string $strategy, ?string $errorCode): self
    {
        return $this->mapItems(static function (ExpertAnalysisCoverageItem $item) use ($materialId, $status, $strategy, $errorCode): ExpertAnalysisCoverageItem {
            if ($item->materialId !== $materialId) {
                return $item;
            }

            return new ExpertAnalysisCoverageItem(
                $item->materialId,
                $item->name,
                $status,
                $strategy ?? $item->processingStrategy,
                $errorCode,
            );
        });
    }

    private static function safeName(string $value): string
    {
        $name = basename(str_replace('\\', '/', $value));
        $name = trim((string) preg_replace('/[\\x00-\\x1F\\x7F]/u', '', $name));

        return mb_substr($name === '' ? 'Материал без названия' : $name, 0, 180, 'UTF-8');
    }
}
