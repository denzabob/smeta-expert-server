<?php

namespace App\Http\Controllers\Api\Pricing\Labor;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLaborProfileRequest;
use App\Http\Requests\UpdateLaborProfileRequest;
use App\Models\LaborProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LaborProfileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $perPage = (int) ($validated['per_page'] ?? 15);
        $query = LaborProfile::query()
            ->ownedBy((int) $request->user()->id)
            ->orderBy('sort_order')
            ->orderBy('title');

        if (array_key_exists('is_active', $validated)) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $profiles = $query->paginate($perPage);

        return response()->json($profiles);
    }

    public function store(StoreLaborProfileRequest $request): JsonResponse
    {
        $payload = $request->validated();
        if ($request->has('is_active')) {
            $payload['is_active'] = $request->boolean('is_active');
        }

        $profile = LaborProfile::create([
            ...$payload,
            'user_id' => $request->user()->id,
            'is_active' => $payload['is_active'] ?? true,
            'sort_order' => $payload['sort_order'] ?? 0,
        ]);

        return response()->json($profile, 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $profile = $this->findOwnedProfile($request, $id);

        return response()->json($profile);
    }

    public function update(UpdateLaborProfileRequest $request, int $id): JsonResponse
    {
        $profile = $this->findOwnedProfile($request, $id);
        $payload = $request->validated();
        if ($request->has('is_active')) {
            $payload['is_active'] = $request->boolean('is_active');
        }

        $profile->update($payload);

        return response()->json($profile->fresh());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $profile = $this->findOwnedProfile($request, $id);
        $profile->delete();

        return response()->json([
            'success' => true,
        ]);
    }

    private function findOwnedProfile(Request $request, int $id): LaborProfile
    {
        return LaborProfile::query()
            ->ownedBy((int) $request->user()->id)
            ->findOrFail($id);
    }
}
