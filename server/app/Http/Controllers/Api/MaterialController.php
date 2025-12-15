<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MaterialController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $materials = Material::where('user_id', auth()->id())->orWhereNull('user_id')->get();
        return response()->json($materials);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'article' => 'required|string|max:255',
            'type' => 'required|in:plate,edge,fitting',
            'price_per_unit' => 'required|numeric|min:0',
            'unit' => 'required|in:м²,м.п.,шт',
            'source_url' => 'nullable|url',
            'is_active' => 'boolean',
        ]);

        $material = auth()->user()->materials()->create($validated);

        return response()->json($material, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
