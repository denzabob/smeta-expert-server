<?php

declare(strict_types=1);

namespace App\Services\Expert;

final readonly class ExpertContextCandidate
{
    /** @param list<string> $origins @param array<string, mixed> $identity */
    public function __construct(
        public string $materialId,
        public string $projectId,
        public string $name,
        public string $mimeType,
        public string $category,
        public array $origins,
        public ?int $currentAttachmentOrder,
        public array $identity,
        public string $availabilityStatus,
        public ?string $attachmentBatchId = null,
    ) {}

    /** @return array<string, mixed> */
    public function descriptor(): array
    {
        return [
            'material_id' => $this->materialId,
            'project_id' => $this->projectId,
            'name' => $this->name,
            'mime_type' => $this->mimeType,
            'category' => $this->category,
            'origins' => $this->origins,
            'current_attachment' => in_array('current', $this->origins, true),
            'current_attachment_order' => $this->currentAttachmentOrder,
            'attachment_batch_id' => $this->attachmentBatchId,
            'active' => in_array('active', $this->origins, true),
            'recently_used' => in_array('recent', $this->origins, true),
            'identity' => $this->identity,
            'availability_status' => $this->availabilityStatus,
        ];
    }
}
