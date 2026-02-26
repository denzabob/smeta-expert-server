<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectRevision;
use App\Models\RevisionPublication;
use App\Services\SnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class ProjectRevisionController extends Controller
{
    public function __construct(
        private SnapshotService $snapshotService
    ) {}

    /**
     * Создать новую ревизию (snapshot) проекта
     * 
     * POST /api/projects/{id}/revisions
     * 
     * @param Project $project
     * @return JsonResponse
     */
    public function store(Project $project): JsonResponse
    {
        // Проверить права доступа
        $this->authorize('update', $project);

        try {
            $revision = $this->snapshotService->createSnapshot(
                $project,
                auth()->id()
            );

            return response()->json([
                'success' => true,
                'revision_id' => $revision->id,
                'number' => $revision->number,
                'snapshot_hash' => $revision->snapshot_hash,
                'created_at' => $revision->created_at->toIso8601String(),
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Failed to create project revision', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Ошибка при создании ревизии',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить список ревизий проекта
     * 
     * GET /api/projects/{id}/revisions
     * 
     * @param Project $project
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Project $project, Request $request): JsonResponse
    {
        // Проверить права доступа
        $this->authorize('view', $project);

        $perPage = $request->input('per_page', 15);
        
        $revisions = $project->revisions()
            ->with('createdBy:id,name,email')
            ->orderByDesc('number')
            ->paginate($perPage);

        $items = $revisions->getCollection()->map(function ($revision) {
            return [
                'id' => $revision->id,
                'number' => $revision->number,
                'status' => $revision->status,
                'created_at' => $revision->created_at?->toIso8601String(),
                'snapshot_hash' => $revision->snapshot_hash,
                'created_by' => $revision->createdBy,
            ];
        });

        return response()->json([
            'success' => true,
            'revisions' => $items,
            'pagination' => [
                'current_page' => $revisions->currentPage(),
                'last_page' => $revisions->lastPage(),
                'per_page' => $revisions->perPage(),
                'total' => $revisions->total(),
            ],
        ]);
    }

    /**
     * Получить конкретную ревизию проекта
     * 
     * GET /api/projects/{id}/revisions/{number}
     * 
     * @param Project $project
     * @param int $number Номер ревизии
     * @return JsonResponse
     */
    public function show(Project $project, int $number): JsonResponse
    {
        // Проверить права доступа
        $this->authorize('view', $project);

        $revision = $project->revisions()
            ->where('number', $number)
            ->with('createdBy:id,name,email')
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'revision' => $revision,
        ]);
    }

    /**
     * Опубликовать ревизию
     * 
     * POST /api/projects/{id}/revisions/{number}/publish
     * 
     * @param Project $project
     * @param int $number
     * @return JsonResponse
     */
    public function publish(Project $project, int $number): JsonResponse
    {
        // Проверить права доступа
        $this->authorize('update', $project);

        $revision = $project->revisions()
            ->where('number', $number)
            ->firstOrFail();

        if ($revision->publish()) {
            $publication = RevisionPublication::firstOrCreate(
                ['project_revision_id' => $revision->id],
                [
                    'public_id' => $this->generatePublicId(),
                    'is_active' => true,
                    'access_level' => 'public_readonly',
                ]
            );

            if (!$publication->is_active) {
                $publication->is_active = true;
                $publication->save();
            }

            return response()->json([
                'success' => true,
                'message' => 'Ревизия опубликована',
                'revision' => $revision->fresh(),
                'publication' => [
                    'public_id' => $publication->public_id,
                    'public_url' => url("/v/{$publication->public_id}"),
                    'access_level' => $publication->access_level,
                    'is_active' => $publication->is_active,
                ],
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => 'Не удалось опубликовать ревизию',
        ], 400);
    }

    /**
     * Снять публикацию (отозвать) ревизии
     *
     * POST /api/projects/{id}/revisions/{number}/unpublish
     */
    public function unpublish(Project $project, int $number): JsonResponse
    {
        $this->authorize('update', $project);

        $revision = $project->revisions()
            ->where('number', $number)
            ->firstOrFail();

        if ($revision->status !== 'published') {
            return response()->json([
                'success' => false,
                'error' => 'Ревизия не опубликована',
            ], 400);
        }

        $publication = RevisionPublication::where('project_revision_id', $revision->id)
            ->orderByDesc('created_at')
            ->first();

        if ($publication) {
            $publication->is_active = false;
            $publication->save();
        }

        if ($revision->markStale()) {
            return response()->json([
                'success' => true,
                'message' => 'Публикация отозвана, ревизия помечена как stale',
                'revision' => $revision->fresh(),
                'publication' => $publication ? [
                    'public_id' => $publication->public_id,
                    'is_active' => $publication->is_active,
                ] : null,
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => 'Не удалось отозвать публикацию',
        ], 400);
    }

    /**
     * Заблокировать ревизию
     * 
     * POST /api/projects/{id}/revisions/{number}/lock
     * 
     * @param Project $project
     * @param int $number
     * @return JsonResponse
     */
    public function lock(Project $project, int $number): JsonResponse
    {
        // Проверить права доступа
        $this->authorize('update', $project);

        $revision = $project->revisions()
            ->where('number', $number)
            ->firstOrFail();

        if ($revision->lock()) {
            return response()->json([
                'success' => true,
                'message' => 'Ревизия заблокирована',
                'revision' => $revision->fresh(),
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => 'Не удалось заблокировать ревизию',
        ], 400);
    }

    /**
     * Получить последнюю ревизию проекта
     * 
     * GET /api/projects/{id}/revisions/latest
     * 
     * @param Project $project
     * @return JsonResponse
     */
    public function latest(Project $project): JsonResponse
    {
        // Проверить права доступа
        $this->authorize('view', $project);

        $revision = $project->revisions()
            ->orderByDesc('number')
            ->first();

        if (!$revision) {
            return response()->json([
                'success' => true,
                'revision' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'revision' => [
                'id' => $revision->id,
                'number' => $revision->number,
                'status' => $revision->status,
                'created_at' => $revision->created_at->toIso8601String(),
                'snapshot_hash' => $revision->snapshot_hash,
            ],
        ]);
    }

    /**
     * Сгенерировать PDF из ревизии
     *
     * GET /api/projects/{id}/revisions/{number}/pdf
     */
    public function pdf(Project $project, int $number)
    {
        $this->authorize('view', $project);

        $revision = $project->revisions()
            ->where('number', $number)
            ->firstOrFail();

        if ($revision->status === 'stale') {
            return response()->json([
                'error' => 'Ревизия устарела и недоступна для PDF',
            ], 403);
        }

        $snapshotRaw = $revision->getRawOriginal('snapshot_json');
        if (is_array($snapshotRaw)) {
            $snapshot = $snapshotRaw;
        } elseif (is_string($snapshotRaw)) {
            $snapshot = json_decode($snapshotRaw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($snapshot)) {
                $jsonError = json_last_error_msg();
                if (json_last_error() === JSON_ERROR_UTF8 && function_exists('mb_convert_encoding')) {
                    $normalized = mb_convert_encoding($snapshotRaw, 'UTF-8', 'UTF-8');
                    $snapshot = json_decode($normalized, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($snapshot)) {
                        $snapshotRaw = $normalized;
                    }
                }
            }
            if (is_string($snapshot)) {
                $snapshotSecond = json_decode($snapshot, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($snapshotSecond)) {
                    $snapshot = $snapshotSecond;
                }
            }
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($snapshot)) {
                \Log::warning('Revision PDF snapshot_json decode failed', [
                    'project_id' => $project->id,
                    'revision_number' => $revision->number,
                    'json_error' => json_last_error_msg(),
                    'snapshot_length' => strlen($snapshotRaw),
                    'snapshot_prefix' => substr($snapshotRaw, 0, 200),
                ]);
                return response()->json([
                    'error' => 'Некорректный snapshot_json',
                    'details' => json_last_error_msg(),
                ], 422);
            }
        } else {
            \Log::warning('Revision PDF snapshot_json missing', [
                'project_id' => $project->id,
                'revision_number' => $revision->number,
                'snapshot_type' => gettype($snapshotRaw),
            ]);
            return response()->json([
                'error' => 'Отсутствует snapshot_json',
            ], 422);
        }

        $pdf = Pdf::loadView('reports.smeta', [
            'report' => $snapshot,
            'qrSvg' => $this->makeQrSvg($this->getPublicUrlForRevision($revision)),
            'revisionNumber' => $revision->number,
            'revisionDate' => $revision->created_at?->format('d.m.Y'),
            'snapshotHashShort' => $revision->snapshot_hash
                ? (substr($revision->snapshot_hash, 0, 8) . '…' . substr($revision->snapshot_hash, -8))
                : null,
            'engineVersion' => $revision->calculation_engine_version,
            'documentToken' => $this->getDocumentTokenForRevision($revision),
        ])
            ->setPaper('a4')
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('isPhpEnabled', false)
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('fontDir', config('dompdf.font_dir'))
            ->setOption('fontCache', config('dompdf.font_cache_dir'));

        $rawFilename = "smeta_{$project->number}_rev_{$revision->number}.pdf";
        $filename = preg_replace('#[\\/:*?"<>|]#', '_', $rawFilename);

        return $pdf->download($filename);
    }

    private function generatePublicId(): string
    {
        do {
            $id = Str::lower(Str::random(10));
        } while (RevisionPublication::where('public_id', $id)->exists());

        return $id;
    }

    private function getPublicUrlForRevision(ProjectRevision $revision): ?string
    {
        $publication = RevisionPublication::where('project_revision_id', $revision->id)
            ->orderByDesc('created_at')
            ->first();

        if (!$publication) {
            $publication = RevisionPublication::create([
                'project_revision_id' => $revision->id,
                'public_id' => $this->generatePublicId(),
                'is_active' => true,
                'access_level' => 'public_readonly',
            ]);
        }

        if (!$publication->is_active) {
            $publication->is_active = true;
            $publication->save();
        }

        return "https://prismcore.ru/v/{$publication->public_id}";
    }

    private function getDocumentTokenForRevision(ProjectRevision $revision): ?string
    {
        $publication = RevisionPublication::where('project_revision_id', $revision->id)
            ->where('is_active', true)
            ->orderByDesc('created_at')
            ->first();

        return $publication?->public_id;
    }

    private function makeQrSvg(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        $options = new QROptions([
            'outputType' => QRCode::OUTPUT_MARKUP_SVG,
            'scale' => 4,
            'quietzoneSize' => 0,
            'outputBase64' => false,
        ]);

        $svg = (new QRCode($options))->render($url);
        if (!is_string($svg) || $svg === '') {
            return null;
        }

        if (str_starts_with($svg, 'data:image/svg+xml;base64,')) {
            return $svg;
        }

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
