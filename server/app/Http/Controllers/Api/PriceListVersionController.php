<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PriceList;
use App\Models\PriceListVersion;
use App\Models\SupplierOperation;
use App\Models\SupplierOperationPrice;
use App\Models\MaterialPrice;
use App\Models\SupplierProductAlias;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PriceListVersionController extends Controller
{
    /**
     * List versions for price list.
     * 
     * GET /api/price-lists/{priceList}/versions
     * Query: page, per_page
     */
    public function index(Request $request, PriceList $priceList): JsonResponse
    {
        $this->authorizePriceList($request, $priceList);

        $versions = $priceList->versions()
            ->select([
                'id',
                'price_list_id',
                'version_number',
                'status',
                'source_type',
                'source_url',
                'original_filename',
                'file_path',
                'manual_label',
                'sha256',
                'size_bytes',
                'captured_at',
                'effective_date',
                'created_at'
            ])
            ->withCount('importSessions as import_session_count')
            ->orderByDesc('effective_date')
            ->orderByDesc('captured_at')
            ->orderByDesc('version_number')
            ->paginate($request->input('per_page', 20));

        // Добавляем количество элементов и файловую информацию для каждой версии
        $versions->load(['importSessions' => function ($query) {
            $query->latest()->limit(1);
        }]);

        $versions->getCollection()->transform(function ($version) use ($priceList) {
            // Подтягиваем file_path и original_filename из import session, если на версии их нет
            $session = $version->importSessions->first();
            if (!$version->original_filename && $session && $session->original_filename) {
                $version->original_filename = $session->original_filename;
            }
            if (!$version->file_path && $session && $session->file_path) {
                $version->file_path = $session->file_path;
            }
            unset($version->importSessions); // не отправляем лишние данные

            if ($priceList->type === 'operations') {
                $version->items_count = \App\Models\OperationPrice::where('price_list_version_id', $version->id)
                    ->count();
            } else {
                $version->items_count = MaterialPrice::where('price_list_version_id', $version->id)
                    ->distinct('material_id')
                    ->count('material_id');
            }
            return $version;
        });

        return response()->json($versions);
    }

    /**
     * Show single version with metadata.
     * 
     * GET /api/price-list-versions/{version}
     */
    public function show(Request $request, PriceListVersion $version): JsonResponse
    {
        $this->authorizeVersion($request, $version);

        $version->load([
            'priceList.supplier',
            'importSessions' => function ($query) {
                $query->latest()->limit(1);
            }
        ]);

        // Подсчет элементов в зависимости от типа
        if ($version->priceList->type === 'operations') {
            $itemsCount = \App\Models\OperationPrice::where('price_list_version_id', $version->id)
                ->count();
        } else {
            $itemsCount = MaterialPrice::where('price_list_version_id', $version->id)
                ->distinct('material_id')
                ->count('material_id');
        }

        $version->items_count = $itemsCount;

        // Подтягиваем file_path и original_filename из import session, если на версии их нет
        $session = $version->importSessions->first();
        if (!$version->original_filename && $session && $session->original_filename) {
            $version->original_filename = $session->original_filename;
        }
        if (!$version->file_path && $session && $session->file_path) {
            $version->file_path = $session->file_path;
        }

        return response()->json($version);
    }

    /**
     * Activate version (make it active, archive previous active).
     * 
     * POST /api/price-lists/{priceList}/versions/{version}/activate
     */
    public function activate(Request $request, PriceList $priceList, PriceListVersion $version): JsonResponse
    {
        $this->authorizePriceList($request, $priceList);

        if ($version->price_list_id !== $priceList->id) {
            return response()->json([
                'message' => 'Версия не принадлежит этому прайс-листу'
            ], 422);
        }

        if ($version->status === PriceListVersion::STATUS_ACTIVE) {
            return response()->json([
                'message' => 'Версия уже активна'
            ], 422);
        }

        try {
            $version->activate();
            
            return response()->json([
                'message' => 'Версия активирована',
                'version' => $version->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка активации: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Archive version (only if not active).
     * 
     * POST /api/price-lists/{priceList}/versions/{version}/archive
     */
    public function archive(Request $request, PriceList $priceList, PriceListVersion $version): JsonResponse
    {
        $this->authorizePriceList($request, $priceList);

        if ($version->price_list_id !== $priceList->id) {
            return response()->json([
                'message' => 'Версия не принадлежит этому прайс-листу'
            ], 422);
        }

        try {
            $version->archive();
            
            return response()->json([
                'message' => 'Версия архивирована',
                'version' => $version->fresh()
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Download source file.
     * 
     * GET /api/price-list-versions/{version}/download
     */
    public function download(Request $request, PriceListVersion $version)
    {
        $this->authorizeVersion($request, $version);

        // Resolve file path: first from version, then from import session
        $filePath = $version->file_path;
        $originalFilename = $version->original_filename;
        $storageDisk = $version->storage_disk ?? 'local';

        if (!$filePath) {
            $session = $version->importSessions()->latest()->first();
            if ($session) {
                $filePath = $session->file_path;
                $originalFilename = $originalFilename ?? $session->original_filename;
                $storageDisk = $session->storage_disk ?? 'local';
            }
        }

        if (!$filePath) {
            return response()->json([
                'message' => 'У версии нет файла для скачивания'
            ], 404);
        }

        if (!Storage::disk($storageDisk)->exists($filePath)) {
            return response()->json([
                'message' => 'Файл не найден в хранилище'
            ], 404);
        }

        return Storage::disk($storageDisk)->download(
            $filePath,
            $originalFilename ?? 'price_list_v' . $version->version_number
        );
    }

    /**
     * Get items (content) of version for audit.
     * 
     * GET /api/price-list-versions/{version}/items
     * Query: q, price_type, unlinked_only, linked_only, unit_mismatch_only, page, per_page
     */
    public function items(Request $request, PriceListVersion $version): JsonResponse
    {
        $this->authorizeVersion($request, $version);

        $type = $version->priceList->type;
        $search = $request->input('q');
        $priceType = $request->input('price_type', 'retail');
        $unlinkedOnly = $request->boolean('unlinked_only');
        $linkedOnly = $request->boolean('linked_only');
        $perPage = $request->input('per_page', 50);

        if ($type === 'operations') {
            return $this->getOperationItems($version, $search, $priceType, $unlinkedOnly, $linkedOnly, $perPage);
        } else {
            return $this->getMaterialItems($version, $search, $priceType, $perPage);
        }
    }

    /**
     * Get operation items for version.
     */
    private function getOperationItems(
        PriceListVersion $version,
        ?string $search,
        string $priceType,
        bool $unlinkedOnly,
        bool $linkedOnly,
        int $perPage
    ): JsonResponse {
        $query = \App\Models\OperationPrice::with([
            'operation' => function ($q) {
                $q->select('id', 'name', 'unit', 'category');
            }
        ])
        ->where('price_list_version_id', $version->id)
        ->where('price_type', $priceType);

        // Search filter
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('source_name', 'like', "%{$search}%")
                  ->orWhereHas('operation', function ($opQuery) use ($search) {
                      $opQuery->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // Linked/unlinked filters by operation binding.
        if ($linkedOnly) {
            $query->whereNotNull('operation_id');
        } elseif ($unlinkedOnly) {
            $query->whereNull('operation_id');
        }

        $items = $query->paginate($perPage);

        // Transform response
        $items->getCollection()->transform(function ($price) {
            $operation = $price->operation;
            
            return [
                'id' => $price->id,
                'price_type' => 'operation',
                'operation_id' => $price->operation_id,
                'material_id' => null,
                'article' => $price->external_key,
                'title' => $price->source_name ?? ($operation ? $operation->name : ''),
                'operation_name' => $operation ? $operation->name : null,
                'unit' => $price->source_unit ?? ($operation ? $operation->unit : ''),
                'category' => $price->category ?? ($operation ? $operation->category : null),
                'price_supplier' => $price->price_per_internal_unit,
                'currency' => $price->currency,
                'match_confidence' => $price->match_confidence,
                'is_linked' => !is_null($price->operation_id),
            ];
        });

        return response()->json($items);
    }

    /**
     * Get material items for version.
     */
    private function getMaterialItems(
        PriceListVersion $version,
        ?string $search,
        string $priceType,
        int $perPage
    ): JsonResponse {
        $query = MaterialPrice::with([
            'material' => function ($q) {
                $q->select('id', 'name', 'unit', 'article');
            }
        ])
        ->where('price_list_version_id', $version->id)
        ->where('supplier_id', $version->priceList->supplier_id)
        ->where('price_type', $priceType);

        // Search filter
        if ($search) {
            $query->whereHas('material', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('search_name', 'like', "%{$search}%")
                  ->orWhere('article', 'like', "%{$search}%");
            });
        }

        $items = $query->paginate($perPage);

        // Transform response
        $items->getCollection()->transform(function ($price) {
            $material = $price->material;
            
            return [
                'id' => $price->id,
                'price_type' => 'material',
                'operation_id' => null,
                'material_id' => $material->id,
                'article' => $material->article,
                'title' => $material->name,
                'unit' => $material->unit,
                'price_supplier' => $price->price_per_internal_unit,
                'currency' => $price->currency,
            ];
        });

        return response()->json($items);
    }

    /**
     * Link an unlinked operation price to a base operation.
     * 
     * PUT /api/operation-prices/{operationPrice}/link
     * Body: { operation_id: int }
     */
    public function linkOperation(Request $request, \App\Models\OperationPrice $operationPrice): JsonResponse
    {
        // Authorize via version → priceList → supplier
        $operationPrice->load('priceListVersion.priceList.supplier');
        if ($operationPrice->priceListVersion->priceList->supplier->user_id !== $request->user()->id) {
            abort(403, 'Доступ запрещен');
        }

        $validated = $request->validate([
            'operation_id' => 'required|integer|exists:operations,id',
            'force_replace' => 'nullable|boolean',
        ]);

        $operation = \App\Models\Operation::findOrFail($validated['operation_id']);

        // Check for duplicate: same base operation already linked in this version
        $duplicate = \App\Models\OperationPrice::where('price_list_version_id', $operationPrice->price_list_version_id)
            ->where('operation_id', $operation->id)
            ->where('id', '!=', $operationPrice->id)
            ->first();

        if ($duplicate) {
            if ($request->boolean('force_replace')) {
                $duplicate->update([
                    'operation_id' => null,
                    'match_confidence' => null,
                ]);
            } else {
                return response()->json([
                    'message' => "Базовая операция «{$operation->name}» уже привязана к другой позиции прайса: «{$duplicate->source_name}»",
                    'duplicate_operation_price_id' => $duplicate->id,
                    'duplicate_source_name' => $duplicate->source_name,
                    'can_force_replace' => true,
                ], 422);
            }
        }

        // Update the price row
        $operationPrice->update([
            'operation_id' => $operation->id,
            'match_confidence' => 'manual',
        ]);

        // Save alias for future auto-matching
        $supplierId = $operationPrice->supplier_id;
        if ($supplierId && $operationPrice->source_name) {
            $externalKey = $operationPrice->external_key 
                ?? SupplierProductAlias::generateExternalKey($operationPrice->source_name);

            SupplierProductAlias::updateOrCreate(
                [
                    'supplier_id' => $supplierId,
                    'external_key' => $externalKey,
                    'internal_item_type' => SupplierProductAlias::TYPE_OPERATION,
                ],
                [
                    'internal_item_id' => $operation->id,
                    'external_name' => $operationPrice->source_name,
                    'supplier_unit' => $operationPrice->source_unit,
                    'conversion_factor' => $operationPrice->conversion_factor ?? 1,
                    'confidence' => 'manual',
                ]
            );
        }

        // Clear price cache
        try {
            \Illuminate\Support\Facades\Cache::flush();
        } catch (\Exception $e) {
            // ignore cache clear failure
        }

        return response()->json([
            'message' => 'Операция привязана',
            'item' => [
                'id' => $operationPrice->id,
                'operation_id' => $operationPrice->operation_id,
                'title' => $operationPrice->source_name,
                'match_confidence' => $operationPrice->match_confidence,
                'is_linked' => true,
                'operation_name' => $operation->name,
            ],
        ]);
    }

    /**
     * Unlink an operation price from its base operation.
     * 
     * DELETE /api/operation-prices/{operationPrice}/link
     */
    public function unlinkOperation(Request $request, \App\Models\OperationPrice $operationPrice): JsonResponse
    {
        // Authorize via version → priceList → supplier
        $operationPrice->load('priceListVersion.priceList.supplier');
        if ($operationPrice->priceListVersion->priceList->supplier->user_id !== $request->user()->id) {
            abort(403, 'Доступ запрещен');
        }

        $operationPrice->update([
            'operation_id' => null,
            'match_confidence' => null,
        ]);

        // Clear price cache
        try {
            \Illuminate\Support\Facades\Cache::flush();
        } catch (\Exception $e) {
            // ignore
        }

        return response()->json([
            'message' => 'Привязка удалена',
            'item' => [
                'id' => $operationPrice->id,
                'operation_id' => null,
                'is_linked' => false,
            ],
        ]);
    }

    /**
     * Create a new version for a price list.
     *
     * POST /api/price-list-versions
     * Body:
     *   price_list_id:   required, exists in price_lists
     *   source_type:     required, file|url|manual
     *   file:            required if source_type=file (uploaded file)
     *   source_url:      required if source_type=url
     *   effective_date:  optional date
     *   notes:           optional string
     *   manual_label:    optional string (label for manual entries)
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'price_list_id' => 'required|integer|exists:price_lists,id',
            'source_type'   => 'required|in:file,url,manual',
            'file'          => 'required_if:source_type,file|file|max:51200', // 50 MB
            'source_url'    => 'required_if:source_type,url|nullable|url|max:2000',
            'effective_date' => 'nullable|date',
            'notes'         => 'nullable|string|max:1000',
            'manual_label'  => 'nullable|string|max:255',
        ]);

        $priceList = PriceList::findOrFail($validated['price_list_id']);

        // Authorize: user must own the supplier
        if ($priceList->supplier->user_id !== $request->user()->id) {
            abort(403, 'Доступ запрещен');
        }

        $sourceType = $validated['source_type'];
        $versionNumber = $priceList->getNextVersionNumber();

        $filePath = null;
        $storageDisk = 'local';
        $originalFilename = null;
        $sha256 = null;
        $sizeBytes = null;
        $sourceUrl = $validated['source_url'] ?? null;

        // Handle file upload
        if ($sourceType === 'file' && $request->hasFile('file')) {
            $file = $request->file('file');
            $originalFilename = $file->getClientOriginalName();
            $sizeBytes = $file->getSize();
            $sha256 = hash_file('sha256', $file->getRealPath());

            $directory = "price-lists/{$priceList->supplier_id}/{$priceList->id}";
            $filename = "v{$versionNumber}_" . time() . '.' . $file->getClientOriginalExtension();
            $filePath = $file->storeAs($directory, $filename, $storageDisk);
        }

        $version = PriceListVersion::create([
            'price_list_id'    => $priceList->id,
            'version_number'   => $versionNumber,
            'source_type'      => $sourceType,
            'source_url'       => $sourceUrl,
            'file_path'        => $filePath,
            'storage_disk'     => $storageDisk,
            'original_filename' => $originalFilename,
            'sha256'           => $sha256,
            'size_bytes'       => $sizeBytes,
            'effective_date'   => $validated['effective_date'] ?? now()->toDateString(),
            'captured_at'      => now(),
            'status'           => PriceListVersion::STATUS_INACTIVE,
            'currency'         => $priceList->default_currency ?? 'RUB',
            'notes'            => $validated['notes'] ?? null,
            'manual_label'     => $validated['manual_label'] ?? null,
        ]);

        return response()->json([
            'id'                => $version->id,
            'price_list_id'     => $version->price_list_id,
            'version_number'    => $version->version_number,
            'source_type'       => $version->source_type,
            'source_url'        => $version->source_url,
            'original_filename' => $version->original_filename,
            'sha256'            => $version->sha256,
            'effective_date'    => $version->effective_date?->format('Y-m-d'),
            'captured_at'       => $version->captured_at?->toISOString(),
            'status'            => $version->status,
        ], 201);
    }

    /**
     * Authorize price list access.
     */
    private function authorizePriceList(Request $request, PriceList $priceList): void
    {
        if ($priceList->supplier->user_id !== $request->user()->id) {
            abort(403, 'Доступ запрещен');
        }
    }

    /**
     * Authorize version access.
     */
    private function authorizeVersion(Request $request, PriceListVersion $version): void
    {
        $version->load('priceList.supplier');
        
        if ($version->priceList->supplier->user_id !== $request->user()->id) {
            abort(403, 'Доступ запрещен');
        }
    }
}
