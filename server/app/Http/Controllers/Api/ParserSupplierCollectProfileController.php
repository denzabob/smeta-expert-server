<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ParserSupplierCollectProfile;
use Illuminate\Http\Request;

class ParserSupplierCollectProfileController extends Controller
{
    public function index(string $supplier)
    {
        $profiles = ParserSupplierCollectProfile::where('supplier_name', $supplier)
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get();

        return response()->json([
            'supplier' => $supplier,
            'profiles' => $profiles,
        ]);
    }

    public function store(string $supplier, Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'config_override' => 'required|array',
            'is_default' => 'sometimes|boolean',
        ]);

        if (!empty($validated['is_default'])) {
            ParserSupplierCollectProfile::where('supplier_name', $supplier)
                ->update(['is_default' => false]);
        }

        $profile = ParserSupplierCollectProfile::create([
            'supplier_name' => $supplier,
            'name' => $validated['name'],
            'config_override' => $validated['config_override'],
            'is_default' => (bool) ($validated['is_default'] ?? false),
        ]);

        return response()->json([
            'profile' => $profile,
        ]);
    }

    public function update(string $supplier, ParserSupplierCollectProfile $profile, Request $request)
    {
        if ($profile->supplier_name !== $supplier) {
            return response()->json(['message' => 'Profile not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'config_override' => 'sometimes|array',
            'is_default' => 'sometimes|boolean',
        ]);

        if (!empty($validated['is_default'])) {
            ParserSupplierCollectProfile::where('supplier_name', $supplier)
                ->where('id', '!=', $profile->id)
                ->update(['is_default' => false]);
        }

        $profile->fill($validated);
        $profile->save();

        return response()->json([
            'profile' => $profile,
        ]);
    }

    public function destroy(string $supplier, ParserSupplierCollectProfile $profile)
    {
        if ($profile->supplier_name !== $supplier) {
            return response()->json(['message' => 'Profile not found'], 404);
        }

        $profile->delete();

        return response()->json([
            'success' => true,
        ]);
    }
}
