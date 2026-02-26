<?php
// app/Http/Controllers/Api/DetailTypeController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DetailType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DetailTypeController extends Controller
{
    public function index()
    {
        return DetailType::where(function ($q) {
            $q->where('origin', 'system')
                ->orWhere('user_id', auth()->id());
        })
            ->withCount('positions')
            ->orderBy('name')
            ->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'edge_processing' => ['required', Rule::in(['O', '=', '||', 'П', 'L', 'none'])],
            'components' => 'nullable|array',
            'components.*.type' => 'required|in:operation',
            'components.*.id' => 'required|integer|exists:operations,id',
            'components.*.quantity' => 'required|numeric|min:0.01',
        ]);

        $validated['user_id'] = auth()->id();
        $validated['origin'] = 'user';

        return DetailType::create($validated);
    }

    public function update(Request $request, DetailType $detailType)
    {
        // Только свои можно редактировать
        if ($detailType->user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'edge_processing' => ['required', Rule::in(['O', '=', '||', 'П', 'L', 'none'])],
            'components' => 'nullable|array',
            'components.*.type' => 'required|in:operation',
            'components.*.id' => 'required|integer|exists:operations,id',
            'components.*.quantity' => 'required|numeric|min:0.01',
        ]);

        $detailType->update($validated);
        return $detailType;
    }

    public function destroy(DetailType $detailType)
    {
        if ($detailType->user_id !== auth()->id()) {
            abort(403);
        }
        $detailType->delete();
        return response()->noContent();
    }
}
