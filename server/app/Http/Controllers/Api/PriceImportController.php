<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PriceImport;
use App\Models\PriceImportItem;
use App\Services\PriceImportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PriceImportController extends Controller
{
    public function __construct(
        private readonly PriceImportService $foundationService,
    ) {}

    /**
     * GET /api/price-imports
     */
    public function index(Request $request): JsonResponse
    {
        $imports = PriceImport::query()
            ->where('user_id', $request->user()->id)
            ->withCount([
                'items',
                'items as linked_count' => fn ($query) => $query->where('status', PriceImportItem::STATUS_LINKED),
                'items as pending_count' => fn ($query) => $query->where('status', PriceImportItem::STATUS_PENDING),
            ])
            ->latest()
            ->limit(20)
            ->get();

        return response()->json([
            'imports' => $imports->map(fn (PriceImport $priceImport) => [
                'id' => $priceImport->id,
                'type' => $priceImport->type,
                'status' => $priceImport->status,
                'created_at' => $priceImport->created_at?->toDateTimeString(),
                'items_count' => (int) $priceImport->items_count,
                'linked_count' => (int) $priceImport->linked_count,
                'pending_count' => (int) $priceImport->pending_count,
            ])->values()->all(),
        ]);
    }

    /**
     * POST /api/price-imports
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['nullable', 'file', 'mimes:xlsx,xls,csv,txt'],
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.name' => ['required_with:items', 'string', 'max:255'],
            'items.*.value' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.unit' => ['required_with:items', 'string', 'max:40'],
            'items.*.parsed_operation_hint' => ['nullable', 'string', 'max:255'],
        ]);

        if (!$request->hasFile('file') && empty($validated['items'])) {
            return response()->json([
                'message' => 'Нужно передать файл или список items.',
            ], 422);
        }

        try {
            $priceImport = $this->foundationService->create(
                $request->user(),
                $validated,
                $request->file('file'),
            );

            return response()->json([
                'import' => $this->formatFoundationImport($priceImport),
            ], 201);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    /**
     * GET /api/price-imports/{id}/items
     */
    public function items(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:' . implode(',', [
                PriceImportItem::STATUS_PENDING,
                PriceImportItem::STATUS_LINKED,
                PriceImportItem::STATUS_IGNORED,
            ])],
        ]);

        $priceImport = PriceImport::query()
            ->whereKey($id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $itemsQuery = $priceImport->items()
            ->orderByDesc('id');

        if (!empty($validated['status'])) {
            $itemsQuery->where('status', $validated['status']);
        }

        return response()->json([
            'import' => [
                'id' => $priceImport->id,
                'type' => $priceImport->type,
                'status' => $priceImport->status,
            ],
            'items' => $itemsQuery
                ->get()
                ->map(fn ($item) => $this->formatFoundationItem($item))
                ->values()
                ->all(),
        ]);
    }

    /**
     * POST /api/price-import-items/{id}/bind
     */
    public function bindItem(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'operation_id' => ['required', 'integer', 'exists:operations,id'],
        ]);

        try {
            $item = $this->foundationService->bindItem(
                $request->user(),
                $id,
                (int) $validated['operation_id'],
            );

            return response()->json([
                'item' => $this->formatFoundationItem($item),
            ]);
        } catch (ModelNotFoundException $exception) {
            abort(404);
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /api/price-import-items/{id}/ignore
     */
    public function ignoreItem(Request $request, int $id): JsonResponse
    {
        try {
            $item = $this->foundationService->ignoreItem(
                $request->user(),
                $id,
            );
        } catch (ModelNotFoundException $exception) {
            abort(404);
        }

        return response()->json([
            'item' => $this->formatFoundationItem($item),
        ]);
    }

    private function formatFoundationImport(PriceImport $priceImport): array
    {
        return [
            'id' => $priceImport->id,
            'user_id' => $priceImport->user_id,
            'type' => $priceImport->type,
            'status' => $priceImport->status,
            'file_path' => $priceImport->file_path,
            'created_at' => $priceImport->created_at?->toDateTimeString(),
            'items' => $priceImport->items
                ->map(fn ($item) => $this->formatFoundationItem($item))
                ->values()
                ->all(),
        ];
    }

    private function formatFoundationItem(PriceImportItem $item): array
    {
        return [
            'id' => $item->id,
            'import_id' => $item->import_id,
            'operation_id' => $item->operation_id,
            'name' => $item->name,
            'value' => $item->value !== null ? (float) $item->value : null,
            'unit' => $item->unit,
            'parsed_operation_hint' => $item->parsed_operation_hint,
            'status' => $item->status,
        ];
    }
}
