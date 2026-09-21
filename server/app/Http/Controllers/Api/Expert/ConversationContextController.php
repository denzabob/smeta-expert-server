<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Expert;

use App\Http\Controllers\Controller;
use App\Models\Expert\ExpertConversation;
use App\Models\Expert\ExpertProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConversationContextController extends Controller
{
    public function show(ExpertProject $project, ExpertConversation $conversation): JsonResponse
    {
        $this->authorize('view', $project);
        $this->assertConversationProject($project, $conversation);

        return response()->json(['active_materials' => $this->items($conversation)]);
    }

    public function update(Request $request, ExpertProject $project, ExpertConversation $conversation): JsonResponse
    {
        $this->authorize('update', $project);
        $this->assertConversationProject($project, $conversation);
        $data = $request->validate([
            'active_material_ids' => ['present', 'array', 'max:100'],
            'active_material_ids.*' => ['required', 'uuid', 'distinct'],
        ]);
        $publicIds = $data['active_material_ids'];

        DB::transaction(function () use ($project, $conversation, $publicIds): void {
            $materials = $project->materials()->whereIn('public_id', $publicIds)->get()->keyBy('public_id');
            if ($materials->count() !== count($publicIds)) {
                throw ValidationException::withMessages(['active_material_ids' => 'Материал не найден в этом проекте.']);
            }
            $conversation->activeMaterials()->sync(array_map(
                static fn (string $id): int => (int) $materials[$id]->id,
                $publicIds,
            ));
        });

        return response()->json(['active_materials' => $this->items($conversation)]);
    }

    private function assertConversationProject(ExpertProject $project, ExpertConversation $conversation): void
    {
        if ((int) $conversation->expert_project_id !== (int) $project->id) {
            abort(404);
        }
    }

    private function items(ExpertConversation $conversation): array
    {
        return $conversation->activeMaterials()->get()->map(static fn ($material): array => [
            'id' => $material->public_id,
            'name' => $material->original_name,
            'mime_type' => $material->mime_type,
        ])->all();
    }
}
