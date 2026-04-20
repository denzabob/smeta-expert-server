<?php

namespace App\Http\Controllers\Api\Pricing\Labor;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLaborProviderRequest;
use App\Http\Requests\UpdateLaborProviderRequest;
use App\Models\LaborProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LaborProviderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $perPage = (int) ($validated['per_page'] ?? 15);
        $query = LaborProvider::query()
            ->ownedBy((int) $request->user()->id)
            ->orderBy('sort_order')
            ->orderBy('title');

        if (array_key_exists('is_active', $validated)) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $providers = $query->paginate($perPage);

        return response()->json($providers);
    }

    public function store(StoreLaborProviderRequest $request): JsonResponse
    {
        $payload = $request->validated();
        if ($request->has('is_active')) {
            $payload['is_active'] = $request->boolean('is_active');
        }

        $provider = LaborProvider::create([
            ...$payload,
            'user_id' => $request->user()->id,
            'is_active' => $payload['is_active'] ?? true,
            'sort_order' => $payload['sort_order'] ?? 0,
        ]);

        return response()->json($provider, 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $provider = $this->findOwnedProvider($request, $id);

        return response()->json($provider);
    }

    public function update(UpdateLaborProviderRequest $request, int $id): JsonResponse
    {
        $provider = $this->findOwnedProvider($request, $id);
        $payload = $request->validated();
        if ($request->has('is_active')) {
            $payload['is_active'] = $request->boolean('is_active');
        }

        $provider->update($payload);

        return response()->json($provider->fresh());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $provider = $this->findOwnedProvider($request, $id);
        $provider->delete();

        return response()->json([
            'success' => true,
        ]);
    }

    private function findOwnedProvider(Request $request, int $id): LaborProvider
    {
        return LaborProvider::query()
            ->ownedBy((int) $request->user()->id)
            ->findOrFail($id);
    }
}
