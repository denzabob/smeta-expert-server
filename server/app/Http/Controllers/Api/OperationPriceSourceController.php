<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Operation;
use App\Models\OperationPriceSource;
use App\Services\OperationPriceSourceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationPriceSourceController extends Controller
{
    public function __construct(
        private readonly OperationPriceSourceService $operationPriceSourceService,
    ) {}

    public function index(Operation $operation): JsonResponse
    {
        $this->ensureOperationReadable($operation);

        return response()->json(
            $this->operationPriceSourceService
                ->getAllForOperation($operation->id)
                ->map(fn (OperationPriceSource $source) => $this->formatSource($source))
                ->values()
        );
    }

    public function store(Request $request, Operation $operation): JsonResponse
    {
        $this->ensureOperationWritable($operation);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:' . implode(',', [
                OperationPriceSource::TYPE_MANUAL,
                OperationPriceSource::TYPE_IMPORT,
                OperationPriceSource::TYPE_EXTERNAL,
            ])],
            'value' => ['required', 'numeric', 'gt:0'],
            'unit' => ['required', 'string', 'max:40'],
            'source_name' => ['nullable', 'string', 'max:255'],
            'document_ref' => ['nullable', 'string', 'max:255'],
        ]);

        $source = $this->operationPriceSourceService->create([
            'operation_id' => $operation->id,
            'type' => $validated['type'],
            'value' => (float) $validated['value'],
            'unit' => trim($validated['unit']),
            'source_name' => $validated['source_name'] ?? null,
            'document_ref' => $validated['document_ref'] ?? null,
        ]);

        return response()->json($this->formatSource($source), 201);
    }

    public function activate(int $id): JsonResponse
    {
        $source = OperationPriceSource::query()
            ->with('operation')
            ->findOrFail($id);

        $this->ensureOperationWritable($source->operation);

        $activated = $this->operationPriceSourceService->activate($source->id);

        return response()->json($this->formatSource($activated));
    }

    public function destroy(int $id): JsonResponse
    {
        $source = OperationPriceSource::query()
            ->with('operation')
            ->findOrFail($id);

        $this->ensureOperationWritable($source->operation);

        $this->operationPriceSourceService->delete($source->id);

        return response()->json(null, 204);
    }

    private function ensureOperationWritable(Operation $operation): void
    {
        if ($operation->origin !== 'user' || (int) $operation->user_id !== (int) auth()->id()) {
            abort(403, 'You can manage price sources only for your own user operations.');
        }
    }

    private function ensureOperationReadable(Operation $operation): void
    {
        if ($operation->origin === 'user' && (int) $operation->user_id !== (int) auth()->id()) {
            abort(404);
        }
    }

    private function formatSource(OperationPriceSource $source): array
    {
        return [
            'id' => $source->id,
            'type' => $source->type,
            'value' => $source->value !== null ? (float) $source->value : null,
            'unit' => $source->unit,
            'source_name' => $source->source_name,
            'document_ref' => $source->document_ref,
            'is_active' => (bool) $source->is_active,
            'created_at' => $source->created_at?->toDateTimeString(),
        ];
    }
}
