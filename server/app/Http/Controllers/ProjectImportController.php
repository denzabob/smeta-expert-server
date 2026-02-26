<?php

namespace App\Http\Controllers;

use App\Models\ImportSession;
use App\Models\Project;
use App\Services\Import\ImportMappingValidator;
use App\Services\Import\ImportSessionService;
use App\Services\Import\PositionsImportService;
use App\Services\Import\SpreadsheetPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ProjectImportController extends Controller
{
    public function __construct(
        private ImportSessionService $sessionService,
        private SpreadsheetPreviewService $previewService,
        private ImportMappingValidator $mappingValidator,
        private PositionsImportService $importService
    ) {}

    /**
     * Upload a file and create a new import session.
     * 
     * POST /api/projects/{project}/imports
     */
    public function upload(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240', // 10MB max
            'sheet_index' => 'nullable|integer|min:0',
            'header_row_index' => 'nullable|integer|min:-1',
            'csv_encoding' => 'nullable|string|in:UTF-8,windows-1251,ISO-8859-1',
            'csv_delimiter' => 'nullable|string|in:,;,\t',
        ]);

        try {
            $session = $this->sessionService->createFromUpload(
                $request->file('file'),
                $request->user(),
                $project,
                [
                    'sheet_index' => $request->input('sheet_index', 0),
                    'header_row_index' => $request->input('header_row_index', 0),
                    'csv_encoding' => $request->input('csv_encoding', 'UTF-8'),
                    'csv_delimiter' => $request->input('csv_delimiter', ','),
                ]
            );

            $response = $this->previewService->getFullPreviewResponse($session);

            return response()->json($response, 201);
        } catch (\Exception $e) {
            Log::error('Import upload failed', [
                'project_id' => $project->id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to process file: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get preview data for an existing import session.
     * 
     * GET /api/imports/{importSession}/preview
     */
    public function preview(Request $request, ImportSession $importSession): JsonResponse
    {
        // Authorize: user must own this session
        if ($importSession->user_id !== $request->user()->id) {
            abort(403, 'You do not have permission to access this import session.');
        }

        $request->validate([
            'sheet_index' => 'nullable|integer|min:0',
            'header_row_index' => 'nullable|integer|min:-1',
            'csv_encoding' => 'nullable|string|in:UTF-8,windows-1251,ISO-8859-1',
            'csv_delimiter' => 'nullable|string|in:,;,\t',
        ]);

        try {
            // Update options if provided
            $options = array_filter([
                'sheet_index' => $request->input('sheet_index'),
                'header_row_index' => $request->input('header_row_index'),
                'csv_encoding' => $request->input('csv_encoding'),
                'csv_delimiter' => $request->input('csv_delimiter'),
            ], fn($v) => $v !== null);

            if (!empty($options)) {
                $importSession = $this->sessionService->updateOptions($importSession, $options);
            }

            $response = $this->previewService->getFullPreviewResponse(
                $importSession,
                $request->input('sheet_index'),
                $request->input('header_row_index')
            );

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Import preview failed', [
                'session_id' => $importSession->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to generate preview: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Save column mappings and options for an import session.
     * 
     * POST /api/imports/{importSession}/mapping
     */
    public function saveMapping(Request $request, ImportSession $importSession): JsonResponse
    {
        // Authorize: user must own this session
        if ($importSession->user_id !== $request->user()->id) {
            abort(403, 'You do not have permission to access this import session.');
        }

        $request->validate([
            'sheet_index' => 'nullable|integer|min:0',
            'header_row_index' => 'nullable|integer|min:-1',
            'options' => 'nullable|array',
            'options.units_length' => 'nullable|string|in:mm,cm,m',
            'options.default_qty_if_empty' => 'nullable|integer|min:1',
            'options.skip_empty_rows' => 'nullable|boolean',
            'mapping' => 'required|array|min:1',
            'mapping.*.column_index' => 'required|integer|min:0',
              'mapping.*.field' => 'nullable|string|in:width,length,qty,name,ignore,kind,price_item_code,height',
            'options.default_kind' => 'nullable|string|in:panel,facade',
            'options.default_facade_material_id' => 'nullable|integer|exists:materials,id',
        ]);

        try {
            // Get column count for validation
            $metadata = $this->previewService->getMetadata($importSession);
            $columnCount = $metadata['column_count'];

            // Prepare options
            $options = array_merge(
                array_filter([
                    'sheet_index' => $request->input('sheet_index'),
                    'header_row_index' => $request->input('header_row_index'),
                ], fn($v) => $v !== null),
                $request->input('options', [])
            );

            // Validate and save mapping
            $session = $this->mappingValidator->validateAndSave(
                $importSession,
                $request->input('mapping'),
                $options,
                $columnCount
            );

            // Get mapping summary
            $summary = $this->mappingValidator->getSummary($session);

            return response()->json([
                'message' => 'Mapping saved successfully',
                'import_session_id' => $session->id,
                'status' => $session->status,
                'mapping_summary' => $summary,
                'options' => $session->options,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Import mapping save failed', [
                'session_id' => $importSession->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to save mapping: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Run the import.
     * 
     * POST /api/projects/{project}/imports/{importSession}/run
     */
    public function run(Request $request, Project $project, ImportSession $importSession): JsonResponse
    {
        $this->authorize('update', $project);

        // Verify session belongs to this project
        if ($importSession->project_id !== $project->id) {
            abort(403, 'This import session does not belong to this project.');
        }

        // Verify user owns the session
        if ($importSession->user_id !== $request->user()->id) {
            abort(403, 'You do not have permission to access this import session.');
        }

        $request->validate([
            'mode' => 'nullable|string|in:append',
        ]);

        try {
            $result = $this->importService->run(
                $importSession,
                $project,
                $request->input('mode', 'append')
            );

            return response()->json([
                'message' => 'Import completed successfully',
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('Import run failed', [
                'session_id' => $importSession->id,
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Import failed: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get import preview (dry run).
     * 
     * GET /api/imports/{importSession}/import-preview
     */
    public function importPreview(Request $request, ImportSession $importSession): JsonResponse
    {
        // Authorize: user must own this session
        if ($importSession->user_id !== $request->user()->id) {
            abort(403, 'You do not have permission to access this import session.');
        }

        if ($importSession->status !== ImportSession::STATUS_MAPPED) {
            return response()->json([
                'message' => 'Mapping must be saved before preview.',
            ], 422);
        }

        try {
            $preview = $this->importService->preview($importSession, 10);

            return response()->json([
                'preview' => $preview,
                'mapping_summary' => $this->mappingValidator->getSummary($importSession),
                'options' => $importSession->options,
            ]);
        } catch (\Exception $e) {
            Log::error('Import preview failed', [
                'session_id' => $importSession->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to generate import preview: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get import session details.
     * 
     * GET /api/imports/{importSession}
     */
    public function show(Request $request, ImportSession $importSession): JsonResponse
    {
        // Authorize: user must own this session
        if ($importSession->user_id !== $request->user()->id) {
            abort(403, 'You do not have permission to access this import session.');
        }

        $importSession->load('columnMappings');

        return response()->json([
            'id' => $importSession->id,
            'project_id' => $importSession->project_id,
            'original_filename' => $importSession->original_filename,
            'file_type' => $importSession->file_type,
            'status' => $importSession->status,
            'header_row_index' => $importSession->header_row_index,
            'sheet_index' => $importSession->sheet_index,
            'options' => $importSession->options,
            'result' => $importSession->result,
            'column_mappings' => $importSession->columnMappings->map(function ($mapping) {
                return [
                    'column_index' => $mapping->column_index,
                    'field' => $mapping->field,
                ];
            }),
            'mapping_summary' => $this->mappingValidator->getSummary($importSession),
            'created_at' => $importSession->created_at,
            'updated_at' => $importSession->updated_at,
        ]);
    }

    /**
     * Delete an import session.
     * 
     * DELETE /api/imports/{importSession}
     */
    public function destroy(Request $request, ImportSession $importSession): JsonResponse
    {
        // Authorize: user must own this session
        if ($importSession->user_id !== $request->user()->id) {
            abort(403, 'You do not have permission to delete this import session.');
        }

        try {
            $this->sessionService->delete($importSession);

            return response()->json([
                'message' => 'Import session deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Import session delete failed', [
                'session_id' => $importSession->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to delete session: ' . $e->getMessage(),
            ], 422);
        }
    }
}
