<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertConversation;
use App\Models\Expert\ExpertMessage;
use App\Models\Expert\ExpertProjectMaterial;

final class ExpertMessageAttachments
{
    /** @param list<string> $publicIds */
    public function persist(ExpertConversation $conversation, ExpertMessage $message, array $publicIds): void
    {
        if ($message->role !== 'user' || $publicIds === []) {
            return;
        }

        $materials = $conversation->project->materials()
            ->whereIn('public_id', $publicIds)
            ->get()
            ->keyBy('public_id');

        if ($materials->count() !== count($publicIds)) {
            throw ExpertMaterialContextException::notFound();
        }

        foreach ($publicIds as $position => $publicId) {
            /** @var ExpertProjectMaterial $material */
            $material = $materials->get($publicId);
            $message->attachments()->create([
                'expert_project_material_id' => $material->id,
                'position' => $position,
                'material_public_id_snapshot' => $material->public_id,
                'original_name_snapshot' => $material->original_name,
                'mime_type_snapshot' => $material->mime_type,
                'size_snapshot' => $material->size,
            ]);
        }
    }

    /** @return list<string> */
    public function publicIds(ExpertMessage $message): array
    {
        return $message->attachments()->pluck('material_public_id_snapshot')->all();
    }

    public function assertAvailable(ExpertMessage $message): void
    {
        if ($message->attachments()->whereNull('expert_project_material_id')->exists()) {
            throw ExpertMaterialContextException::originalUnavailable();
        }
    }
}
