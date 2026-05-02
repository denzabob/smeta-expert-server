<?php
// app/Http/Controllers/Api/DetailTypeController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DetailType;
use App\Models\DetailTypeOperation;
use App\Models\ProjectPosition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
            'edge_processing' => ['required', Rule::in(ProjectPosition::EDGE_SCHEMES)],
            'components' => 'nullable|array',
            'components.*.type' => 'required|in:operation',
            'components.*.id' => 'nullable|integer|exists:operations,id',
            'components.*.operation_id' => 'nullable|integer|exists:operations,id',
            'components.*.quantity' => 'required',
        ]);
        $this->validateComponentOperationIds($validated['components'] ?? []);

        $validated['user_id'] = auth()->id();
        $validated['origin'] = 'user';

        return DB::transaction(function () use ($validated) {
            $detailType = DetailType::create($validated);
            $this->syncDetailTypeOperations($detailType, $validated['components'] ?? []);

            return $detailType->fresh();
        });
    }

    public function update(Request $request, DetailType $detailType)
    {
        // Только свои можно редактировать
        if ($detailType->user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'edge_processing' => ['required', Rule::in(ProjectPosition::EDGE_SCHEMES)],
            'components' => 'nullable|array',
            'components.*.type' => 'required|in:operation',
            'components.*.id' => 'nullable|integer|exists:operations,id',
            'components.*.operation_id' => 'nullable|integer|exists:operations,id',
            'components.*.quantity' => 'required',
        ]);
        if (array_key_exists('components', $validated)) {
            $this->validateComponentOperationIds($validated['components'] ?? []);
        }

        return DB::transaction(function () use ($detailType, $validated) {
            $components = array_key_exists('components', $validated)
                ? ($validated['components'] ?? [])
                : ($detailType->components ?? []);

            $detailType->update($validated);
            $this->syncDetailTypeOperations($detailType, $components);

            return $detailType->fresh();
        });
    }

    public function destroy(DetailType $detailType)
    {
        if ($detailType->user_id !== auth()->id()) {
            abort(403);
        }
        $detailType->delete();
        return response()->noContent();
    }

    private function validateComponentOperationIds(array $components): void
    {
        foreach ($components as $index => $component) {
            if (!empty($component['id']) || !empty($component['operation_id'])) {
                continue;
            }

            throw ValidationException::withMessages([
                "components.{$index}.id" => 'Для операции типа детали требуется id операции.',
            ]);
        }
    }

    private function syncDetailTypeOperations(DetailType $detailType, array $components): void
    {
        DetailTypeOperation::where('detail_type_id', $detailType->id)->delete();

        $seenOperationIds = [];
        foreach ($components as $component) {
            $operationId = (int) ($component['operation_id'] ?? $component['id'] ?? 0);
            if ($operationId <= 0 || isset($seenOperationIds[$operationId])) {
                continue;
            }

            $seenOperationIds[$operationId] = true;

            DetailTypeOperation::create([
                'detail_type_id' => $detailType->id,
                'operation_id' => $operationId,
                'quantity_formula' => (string) ($component['quantity'] ?? '1'),
            ]);
        }
    }
}
