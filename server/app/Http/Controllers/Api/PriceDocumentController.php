<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PriceList;
use App\Models\PriceListVersion;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Price Document Controller (DMS mode).
 * 
 * Simple upload/list of price documents (PDF/XLSX/etc.) WITHOUT parsing.
 * Used for facade price references — file is stored as a price_list_version
 * and can be linked to facade quotes.
 * 
 * This does NOT replace the ETL import (PriceImportController) which parses
 * Excel/CSV into operation/material prices.
 */
class PriceDocumentController extends Controller
{
    /**
     * Upload a price document (no parsing).
     * 
     * POST /api/suppliers/{supplier}/price-documents
     * 
     * Payload (multipart/form-data):
     *   purpose:        'facades' | 'operations' (default: 'facades')
     *   source_type:    'file' | 'url' (required)
     *   file:           required if source_type=file
     *   source_url:     required if source_type=url
     *   title:          optional price list name (auto-generated if empty)
     *   effective_date: optional date
     */
    public function store(Request $request, Supplier $supplier): JsonResponse
    {
        $this->authorizeSupplier($request, $supplier);

        $validated = $request->validate([
            'purpose'        => 'sometimes|in:facades,finished_products,operations',
            'source_type'    => 'required|in:file,url',
            'file'           => 'required_if:source_type,file|file|mimes:pdf,xlsx,xls,csv,ods,html,htm,doc,docx,jpg,jpeg,png|max:10240',
            'source_url'     => 'required_if:source_type,url|nullable|url|max:2000',
            'title'          => 'nullable|string|max:255',
            'effective_date' => 'nullable|date',
        ]);

        $purpose = $validated['purpose'] ?? 'facades';
        if ($purpose === 'finished_products') {
            $purpose = 'facades';
        }
        $sourceType = $validated['source_type'];
        $type = $purpose === 'facades' ? PriceList::TYPE_MATERIALS : PriceList::TYPE_OPERATIONS;

        return DB::transaction(function () use ($request, $supplier, $validated, $purpose, $sourceType, $type) {
            // Find or create a price list for this supplier+purpose
            $priceList = $this->findOrCreatePriceList($supplier, $type, $purpose, $validated['title'] ?? null);

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

                $directory = "price-lists/{$supplier->id}/{$priceList->id}";
                $filename = "v{$versionNumber}_" . time() . '.' . $file->getClientOriginalExtension();
                $filePath = $file->storeAs($directory, $filename, $storageDisk);
            }

            // Create version
            $version = PriceListVersion::create([
                'price_list_id'     => $priceList->id,
                'version_number'    => $versionNumber,
                'source_type'       => $sourceType,
                'source_url'        => $sourceUrl,
                'file_path'         => $filePath,
                'storage_disk'      => $storageDisk,
                'original_filename' => $originalFilename,
                'sha256'            => $sha256,
                'size_bytes'        => $sizeBytes,
                'effective_date'    => $validated['effective_date'] ?? now()->toDateString(),
                'captured_at'       => now(),
                'status'            => PriceListVersion::STATUS_ACTIVE,
                'currency'          => $priceList->default_currency ?? 'RUB',
            ]);

            // Auto-activate: archive previous active versions
            PriceListVersion::where('price_list_id', $priceList->id)
                ->where('id', '!=', $version->id)
                ->where('status', PriceListVersion::STATUS_ACTIVE)
                ->update(['status' => PriceListVersion::STATUS_ARCHIVED]);

            return response()->json([
                'price_list' => [
                    'id'   => $priceList->id,
                    'name' => $priceList->name,
                    'type' => $priceList->type,
                ],
                'version' => [
                    'id'                => $version->id,
                    'version_number'    => $version->version_number,
                    'captured_at'       => $version->captured_at?->toISOString(),
                    'effective_date'    => $version->effective_date?->format('Y-m-d'),
                    'source_type'       => $version->source_type,
                    'original_filename' => $version->original_filename,
                    'source_url'        => $version->source_url,
                    'status'            => $version->status,
                    'size_bytes'        => $version->size_bytes,
                ],
            ], 201);
        });
    }

    /**
     * List price documents for a supplier.
     * 
     * GET /api/suppliers/{supplier}/price-documents?purpose=facades
     */
    public function index(Request $request, Supplier $supplier): JsonResponse
    {
        $this->authorizeSupplier($request, $supplier);

        $purpose = $request->input('purpose', 'facades');
        if ($purpose === 'finished_products') {
            $purpose = 'facades';
        }
        $type = $purpose === 'facades' ? PriceList::TYPE_MATERIALS : PriceList::TYPE_OPERATIONS;

        $priceLists = $supplier->priceLists()
            ->where('type', $type)
            ->get();

        if ($priceLists->isEmpty()) {
            return response()->json([
                'data' => [],
                'total' => 0,
            ]);
        }

        $priceListIds = $priceLists->pluck('id');
        $priceListMap = $priceLists->keyBy('id');

        $versions = PriceListVersion::whereIn('price_list_id', $priceListIds)
            ->orderByDesc('captured_at')
            ->orderByDesc('version_number')
            ->paginate($request->input('per_page', 20));

        $versions->getCollection()->transform(function ($version) use ($priceListMap) {
            $pl = $priceListMap[$version->price_list_id] ?? null;
            return [
                'version_id'        => $version->id,
                'price_list_id'     => $version->price_list_id,
                'price_list_name'   => $pl ? $pl->name : '—',
                'version_number'    => $version->version_number,
                'captured_at'       => $version->captured_at?->toISOString(),
                'effective_date'    => $version->effective_date?->format('Y-m-d'),
                'source_type'       => $version->source_type,
                'original_filename' => $version->original_filename,
                'source_url'        => $version->source_url,
                'status'            => $version->status,
                'size_bytes'        => $version->size_bytes,
            ];
        });

        return response()->json($versions);
    }

    /**
     * Activate a specific version (archive previous active).
     * 
     * POST /api/suppliers/{supplier}/price-documents/{version}/activate
     */
    public function activate(Request $request, Supplier $supplier, PriceListVersion $version): JsonResponse
    {
        $this->authorizeSupplier($request, $supplier);
        $this->authorizeVersion($version, $supplier);

        if ($version->status === PriceListVersion::STATUS_ACTIVE) {
            return response()->json(['message' => 'Уже активна'], 422);
        }

        $version->activate();

        return response()->json([
            'message' => 'Версия активирована',
            'version' => $version->fresh(),
        ]);
    }

    /**
     * Archive a specific version.
     * 
     * POST /api/suppliers/{supplier}/price-documents/{version}/archive
     */
    public function archiveVersion(Request $request, Supplier $supplier, PriceListVersion $version): JsonResponse
    {
        $this->authorizeSupplier($request, $supplier);
        $this->authorizeVersion($version, $supplier);

        try {
            $version->archive();
            return response()->json([
                'message' => 'Версия архивирована',
                'version' => $version->fresh(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // =========== PRIVATE HELPERS ===========

    /**
     * Find or create a price list for supplier + type + purpose.
     */
    private function findOrCreatePriceList(Supplier $supplier, string $type, string $purpose, ?string $title): PriceList
    {
        // Look for existing PL with matching purpose in metadata
        $existing = $supplier->priceLists()
            ->where('type', $type)
            ->get()
            ->first(function ($pl) use ($purpose) {
                $meta = $pl->metadata ?? [];
                return ($meta['purpose'] ?? null) === $purpose;
            });

        if ($existing) {
            return $existing;
        }

        // If title provided, check for name match
        if ($title) {
            $byName = $supplier->priceLists()
                ->where('type', $type)
                ->where('name', $title)
                ->first();
            if ($byName) {
                // Tag with purpose
                $meta = $byName->metadata ?? [];
                $meta['purpose'] = $purpose;
                $meta['domain'] = $purpose === 'facades'
                    ? PriceList::DOMAIN_FINISHED_PRODUCTS
                    : PriceList::DOMAIN_OPERATIONS;
                $byName->update(['metadata' => $meta]);
                return $byName;
            }
        }

        // Create new
        $defaultName = $title ?: ($purpose === 'facades' ? 'Прайс фасадов' : 'Прайс операций');

        return $supplier->priceLists()->create([
            'name'             => $defaultName,
            'type'             => $type,
            'default_currency' => 'RUB',
            'is_active'        => true,
            'metadata'         => [
                'purpose' => $purpose,
                'domain' => $purpose === 'facades'
                    ? PriceList::DOMAIN_FINISHED_PRODUCTS
                    : PriceList::DOMAIN_OPERATIONS,
            ],
        ]);
    }

    private function authorizeSupplier(Request $request, Supplier $supplier): void
    {
        if ($supplier->user_id !== $request->user()->id) {
            abort(403, 'Доступ запрещён');
        }
    }

    private function authorizeVersion(PriceListVersion $version, Supplier $supplier): void
    {
        $priceList = $version->priceList;
        if (!$priceList || $priceList->supplier_id !== $supplier->id) {
            abort(404, 'Версия не найдена');
        }
    }
}
