<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PriceImportSession;
use App\Models\PriceList;
use App\Models\Supplier;
use App\Services\PriceImport\ParsingException;
use App\Services\PriceImport\PriceImportSessionService;
use App\Services\PriceImport\DuplicateImportException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PriceImportController extends Controller
{
    public function __construct(
        private PriceImportSessionService $sessionService
    ) {}

    /**
     * Upload file and create import session.
     * 
     * POST /api/price-imports/upload
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,html,htm|max:10240',
            'target_type' => 'required|in:operations,materials',
            // Поставщик и прайс-лист обязательны для архитектуры snapshot-prices
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'price_list_id' => 'required|integer|exists:price_lists,id',
            'header_row_index' => 'nullable|integer|min:0',
            'sheet_index' => 'nullable|integer|min:0',
            'csv_encoding' => 'nullable|string|in:UTF-8,windows-1251,ISO-8859-1',
            'csv_delimiter' => 'nullable|string|in:,;,\t',
        ]);

        // Validate supplier ownership
        $supplier = Supplier::find($request->supplier_id);
        if ($supplier->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Доступ к поставщику запрещен'], 403);
        }

        // Validate price list ownership and belongs to supplier
        $priceList = PriceList::with('supplier')->find($request->price_list_id);
        if ($priceList->supplier->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Доступ к прайс-листу запрещен'], 403);
        }
        if ($priceList->supplier_id !== (int)$request->supplier_id) {
            return response()->json(['message' => 'Прайс-лист не принадлежит выбранному поставщику'], 422);
        }

        try {
            $session = $this->sessionService->createFromUpload(
                $request->file('file'),
                $request->user(),
                $request->input('target_type'),
                $request->input('supplier_id'),
                $request->input('price_list_id'),
                [
                    'header_row_index' => $request->input('header_row_index', 0),
                    'sheet_index' => $request->input('sheet_index', 0),
                    'csv_encoding' => $request->input('csv_encoding', 'UTF-8'),
                    'csv_delimiter' => $request->input('csv_delimiter', ','),
                ]
            );

            $preview = $this->sessionService->getPreview($session);

            return response()->json([
                'session' => $session,
                'preview' => $preview,
            ], 201);

        } catch (DuplicateImportException $e) {
            return response()->json(array_merge($e->toArray(), [
                'can_reuse' => true,
            ]), 409);
        } catch (ParsingException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'details' => $e->getDetails(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Price import upload failed', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Ошибка загрузки файла: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Create import session from pasted content.
     * 
     * POST /api/price-imports/paste
     */
    public function paste(Request $request): JsonResponse
    {
        $request->validate([
            'content' => 'required|string|min:10',
            'target_type' => 'required|in:operations,materials',
            // Поставщик и прайс-лист обязательны для архитектуры snapshot-prices
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'price_list_id' => 'required|integer|exists:price_lists,id',
            'header_row_index' => 'nullable|integer|min:0',
            'csv_delimiter' => 'nullable|string',
        ]);

        // Validate supplier ownership
        $supplier = Supplier::find($request->supplier_id);
        if ($supplier->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Доступ к поставщику запрещен'], 403);
        }

        // Validate price list ownership and belongs to supplier
        $priceList = PriceList::with('supplier')->find($request->price_list_id);
        if ($priceList->supplier->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Доступ к прайс-листу запрещен'], 403);
        }
        if ($priceList->supplier_id !== (int)$request->supplier_id) {
            return response()->json(['message' => 'Прайс-лист не принадлежит выбранному поставщику'], 422);
        }

        try {
            $session = $this->sessionService->createFromPaste(
                $request->input('content'),
                $request->user(),
                $request->input('target_type'),
                $request->input('supplier_id'),
                $request->input('price_list_id'),
                [
                    'header_row_index' => $request->input('header_row_index', 0),
                    'csv_delimiter' => $request->input('csv_delimiter', "\t"),
                ]
            );

            $preview = $this->sessionService->getPreview($session);

            return response()->json([
                'session' => $session,
                'preview' => $preview,
            ], 201);

        } catch (ParsingException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'details' => $e->getDetails(),
            ], 422);
        }
    }

    /**
     * Reuse an existing completed import session to create a fresh remapping session
     * without re-uploading the same file.
     *
     * POST /api/price-imports/reuse
     */
    public function reuse(Request $request): JsonResponse
    {
        $request->validate([
            'existing_session_id' => 'required|string|exists:price_import_sessions,id',
            'target_type' => 'required|in:operations,materials',
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'price_list_id' => 'required|integer|exists:price_lists,id',
            'header_row_index' => 'nullable|integer|min:0',
            'sheet_index' => 'nullable|integer|min:0',
        ]);

        $existing = PriceImportSession::findOrFail($request->input('existing_session_id'));
        $this->authorizeSession($request, $existing);

        $supplier = Supplier::find($request->supplier_id);
        if ($supplier->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Доступ к поставщику запрещен'], 403);
        }

        $priceList = PriceList::with('supplier')->find($request->price_list_id);
        if ($priceList->supplier->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Доступ к прайс-листу запрещен'], 403);
        }
        if ($priceList->supplier_id !== (int)$request->supplier_id) {
            return response()->json(['message' => 'Прайс-лист не принадлежит выбранному поставщику'], 422);
        }

        try {
            $session = $this->sessionService->createFromExistingSession(
                $existing,
                $request->user(),
                $request->input('target_type'),
                (int) $request->input('supplier_id'),
                (int) $request->input('price_list_id'),
                [
                    'header_row_index' => $request->input('header_row_index', 0),
                    'sheet_index' => $request->input('sheet_index', 0),
                ]
            );

            $preview = $this->sessionService->getPreview($session);

            return response()->json([
                'session' => $session,
                'preview' => $preview,
                'reused_from_session_id' => $existing->id,
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get session details and preview.
     * 
     * GET /api/price-imports/{session}
     */
    public function show(Request $request, PriceImportSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);

        // Load relations for resume functionality
        $session->load(['supplier:id,name', 'priceListVersion.priceList:id,name,type']);

        $preview = $this->sessionService->getPreview($session);

        return response()->json([
            'session' => $session,
            'preview' => $preview,
        ]);
    }

    /**
     * Update session settings (header row, sheet index).
     * 
     * PATCH /api/price-imports/{session}
     */
    public function updateSettings(Request $request, PriceImportSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);

        $validated = $request->validate([
            'header_row_index' => 'sometimes|integer|min:0',
            'sheet_index' => 'sometimes|integer|min:0',
        ]);

        $needsReparse = false;

        if (isset($validated['sheet_index']) && $validated['sheet_index'] !== $session->sheet_index) {
            $session->sheet_index = $validated['sheet_index'];
            $needsReparse = true;
        }

        if (isset($validated['header_row_index'])) {
            $session->header_row_index = $validated['header_row_index'];
        }

        $session->save();

        // If sheet changed, re-parse the file
        if ($needsReparse && $session->file_path) {
            $this->sessionService->reparseFile($session);
        }

        $preview = $this->sessionService->getPreview($session);

        return response()->json([
            'session' => $session,
            'preview' => $preview,
        ]);
    }

    /**
     * Save column mapping and run dry run.
     * 
     * POST /api/price-imports/{session}/mapping
     */
    public function saveMapping(Request $request, PriceImportSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);

        $request->validate([
            'mapping' => 'required|array',
            'mapping.*' => 'nullable|string',
            'header_row_index' => 'nullable|integer|min:0',
        ]);

        if ($request->has('header_row_index')) {
            $session->header_row_index = $request->input('header_row_index');
            $session->save();
        }

        try {
            $result = $this->sessionService->saveMapping(
                $session,
                $request->input('mapping')
            );

            return response()->json($result);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (ParsingException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'details' => $e->getDetails(),
            ], 422);
        }
    }

    /**
     * Get resolution queue.
     * 
     * GET /api/price-imports/{session}/resolution
     */
    public function resolution(Request $request, PriceImportSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);

        $filter = $request->input('filter'); // 'new', 'ambiguous', 'auto_matched'

        $result = $this->sessionService->getResolutionQueue($session, $filter);

        return response()->json($result);
    }

    /**
     * Apply bulk action to resolution queue.
     * 
     * POST /api/price-imports/{session}/bulk-action
     */
    public function bulkAction(Request $request, PriceImportSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);

        $request->validate([
            'action' => 'required|in:accept_as_new,ignore,link,set_conversion',
            'row_indexes' => 'required|array|min:1',
            'row_indexes.*' => 'integer',
            'params' => 'nullable|array',
            'params.internal_item_id' => 'nullable|integer',
            'params.conversion_factor' => 'nullable|numeric|min:0.000001',
            'params.supplier_unit' => 'nullable|string|max:50',
            'params.internal_unit' => 'nullable|string|max:50',
        ]);

        try {
            $result = $this->sessionService->applyBulkAction(
                $session,
                $request->input('action'),
                $request->input('row_indexes'),
                $request->input('params', [])
            );

            return response()->json($result);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Execute import.
     * 
     * POST /api/price-imports/{session}/execute
     */
    public function execute(Request $request, PriceImportSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);

        $request->validate([
            'decisions' => 'nullable|array',
            'decisions.*.row_index' => 'required|integer',
            'decisions.*.action' => 'required|in:link,create,ignore',
            'decisions.*.internal_item_id' => 'nullable|integer',
            'decisions.*.conversion_factor' => 'nullable|numeric|min:0.000001',
            'decisions.*.supplier_unit' => 'nullable|string|max:50',
            'decisions.*.internal_unit' => 'nullable|string|max:50',
        ]);

        try {
            $result = $this->sessionService->execute(
                $session,
                $request->input('decisions', [])
            );

            return response()->json([
                'message' => 'Импорт успешно выполнен',
                'result' => $result,
            ]);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Price import execution failed', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Ошибка выполнения импорта: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete import session.
     * 
     * DELETE /api/price-imports/{session}
     */
    public function destroy(Request $request, PriceImportSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);

        // Can only delete non-completed sessions
        if ($session->status === PriceImportSession::STATUS_COMPLETED) {
            return response()->json([
                'message' => 'Нельзя удалить завершенную сессию импорта',
            ], 422);
        }

        // Delete associated file
        if ($session->file_path) {
            \Illuminate\Support\Facades\Storage::delete($session->file_path);
        }

        $session->delete();

        return response()->json(null, 204);
    }

    /**
     * Cancel import session with full rollback.
     * 
     * POST /api/price-imports/{session}/cancel
     * 
     * Удаляет сессию и связанную пустую версию прайса если она была создана.
     */
    public function cancel(Request $request, PriceImportSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);

        // Can't cancel completed sessions
        if ($session->status === PriceImportSession::STATUS_COMPLETED) {
            return response()->json([
                'message' => 'Нельзя отменить завершённую сессию импорта. Используйте удаление версии прайса.',
            ], 422);
        }

        $rollbackInfo = [
            'session_deleted' => false,
            'file_deleted' => false,
            'version_deleted' => false,
            'version_id' => null,
        ];

        try {
            \Illuminate\Support\Facades\DB::beginTransaction();

            // Check if version was created and is empty
            if ($session->price_list_version_id) {
                $version = $session->priceListVersion;
                $rollbackInfo['version_id'] = $version?->id;

                if ($version) {
                    // Check if version has any items (operations or materials)
                    $hasItems = $version->operationPrices()->exists() || 
                                $version->materialPrices()->exists() ||
                                // Also check legacy SupplierOperationPrice
                                \App\Models\SupplierOperationPrice::where('price_list_version_id', $version->id)->exists();

                    if (!$hasItems && $version->status === \App\Models\PriceListVersion::STATUS_INACTIVE) {
                        // Safe to delete empty inactive version
                        $version->delete();
                        $rollbackInfo['version_deleted'] = true;
                    }
                }
            }

            // IMPORTANT: Do not delete source file on cancel.
            // Users may want to resume/reuse interrupted imports without re-upload.
            // File lifecycle should be managed by retention/cleanup policy, not by cancel action.

            // Update session status to cancelled instead of deleting
            $session->status = PriceImportSession::STATUS_CANCELLED;
            $session->result = ['cancelled_at' => now()->toIso8601String(), 'rollback' => $rollbackInfo];
            $session->save();
            $rollbackInfo['session_deleted'] = true;

            \Illuminate\Support\Facades\DB::commit();

            return response()->json([
                'message' => 'Импорт отменён',
                'rollback' => $rollbackInfo,
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            Log::error('Price import cancel failed', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Ошибка отмены импорта: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List user's import sessions.
     * 
     * GET /api/price-imports
     */
    public function index(Request $request): JsonResponse
    {
        $query = PriceImportSession::forUser($request->user()->id)
            ->with(['supplier:id,name', 'priceListVersion.priceList:id,name,type']);
        
        // Filter by supplier
        if ($request->has('supplier_id')) {
            $query->where('supplier_id', $request->input('supplier_id'));
        }
        
        // Filter by target type
        if ($request->has('target_type')) {
            $query->where('target_type', $request->input('target_type'));
        }
        
        // Filter by status (single or pending which means multiple non-completed statuses)
        if ($request->input('status') === 'pending') {
            $query->whereIn('status', [
                PriceImportSession::STATUS_CREATED,
                PriceImportSession::STATUS_MAPPING_REQUIRED,
                PriceImportSession::STATUS_RESOLUTION_REQUIRED,
            ]);
        } elseif ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }
        
        $sessions = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json($sessions);
    }

    /**
     * Authorize that user owns this session.
     */
    private function authorizeSession(Request $request, PriceImportSession $session): void
    {
        if ($session->user_id !== $request->user()->id) {
            abort(403, 'Доступ запрещен');
        }
    }
}
