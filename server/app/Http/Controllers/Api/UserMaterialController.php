<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserMaterial;
use Illuminate\Http\Request;

class UserMaterialController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()?->id;
        if (!$userId) {
            return response()->json([], 200);
        }

        $materials = UserMaterial::where('user_id', $userId)
            ->orderBy('name')
            ->get();

        return response()->json($materials);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $this->validatePayload($request);
        $validated['user_id'] = $user->id;

        $material = UserMaterial::create($validated);

        return response()->json($material, 201);
    }

    public function show(Request $request, string $id)
    {
        $material = $this->findOwned($request, $id);
        return response()->json($material);
    }

    public function update(Request $request, string $id)
    {
        $material = $this->findOwned($request, $id);
        $validated = $this->validatePayload($request);

        $material->update($validated);

        return response()->json($material);
    }

    public function destroy(Request $request, string $id)
    {
        $material = $this->findOwned($request, $id);
        $material->delete();

        return response()->noContent();
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'article' => 'required|string|max:255',
            'type' => 'required|in:plate,edge,fitting',
            'unit' => 'required|in:м²,м.п.,шт',
            'price_per_unit' => 'required|numeric|min:0',
            'supplier' => 'nullable|string|max:255',
            'source_url' => 'nullable|url',
            'is_active' => 'boolean',
        ]);
    }

    private function findOwned(Request $request, string $id): UserMaterial
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        return UserMaterial::where('user_id', $user->id)->findOrFail($id);
    }
}


