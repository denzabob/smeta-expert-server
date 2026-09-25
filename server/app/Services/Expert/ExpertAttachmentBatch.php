<?php

declare(strict_types=1);

namespace App\Services\Expert;

final readonly class ExpertAttachmentBatch
{
    /** @param list<string> $orderedMaterialIds */
    public function __construct(
        public ?string $messageId,
        public array $orderedMaterialIds,
        public ?string $createdAt = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'message_id' => $this->messageId,
            'ordered_material_ids' => $this->orderedMaterialIds,
        ];

        if ($this->createdAt !== null) {
            $data['created_at'] = $this->createdAt;
        }

        return $data;
    }
}
