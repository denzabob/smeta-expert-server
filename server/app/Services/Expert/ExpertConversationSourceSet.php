<?php

declare(strict_types=1);

namespace App\Services\Expert;

final readonly class ExpertConversationSourceSet
{
    /** @param list<string> $materialIds @param list<string> $primaryIds @param list<string> $comparisonIds */
    public function __construct(
        public string $messageId,
        public array $materialIds,
        public array $primaryIds,
        public array $comparisonIds,
        public int $snapshotVersion,
        public bool $crossDocument,
    ) {}

    /** @param array<string, mixed> $snapshot */
    public static function fromSnapshot(string $messageId, array $snapshot): self
    {
        $version = (int) ($snapshot['version'] ?? 2);
        if ($version >= 3) {
            $selected = is_array($snapshot['selected_sources'] ?? null) ? $snapshot['selected_sources'] : [];
            $ids = [];
            $primary = [];
            $comparison = [];
            foreach ($selected as $source) {
                if (! is_array($source) || ! is_string($source['material_id'] ?? null) || $source['material_id'] === '') {
                    continue;
                }
                $id = $source['material_id'];
                $ids[] = $id;
                if (($source['role'] ?? null) === 'primary') {
                    $primary[] = $id;
                } elseif (($source['role'] ?? null) === 'comparison') {
                    $comparison[] = $id;
                }
            }
        } else {
            $ids = is_array($snapshot['resolved_material_ids'] ?? null) ? $snapshot['resolved_material_ids'] : [];
            $primary = [];
            $comparison = [];
        }
        $intent = is_array($snapshot['task_intent'] ?? null) ? $snapshot['task_intent'] : [];

        return new self(
            $messageId,
            self::ids($ids),
            self::ids($primary),
            self::ids($comparison),
            $version,
            (bool) ($intent['cross_document'] ?? false) || ($intent['task_type'] ?? null) === ExpertTaskIntent::COMPARE,
        );
    }

    /** @param mixed $ids @return list<string> */
    private static function ids(mixed $ids): array
    {
        return array_values(array_unique(array_filter(
            is_array($ids) ? $ids : [],
            static fn (mixed $id): bool => is_string($id) && $id !== '',
        )));
    }
}
