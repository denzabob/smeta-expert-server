<?php

namespace App\Http\Controllers\Api\Expert;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expert\MaterialUploadRequest;
use App\Http\Resources\Expert\MaterialResource;
use App\Models\Expert\ExpertProject;
use App\Models\Expert\ExpertProjectMaterial;
use App\Services\Expert\ExpertMaterialService;
use App\Services\Expert\ExpertMaterialThumbnailException;
use App\Services\Expert\ExpertMaterialThumbnailService;
use App\Services\Expert\ExpertStorageService;
use App\Services\Expert\ExpertStorageException;
use App\Services\Expert\ExpertStorageQuotaException;
use Illuminate\Http\Request;

class MaterialController extends Controller
{
    public function __construct(
        private readonly ExpertMaterialService $service,
        private readonly ExpertMaterialThumbnailService $thumbnails,
        private readonly ExpertStorageService $storage,
    ) {}

    public function index(ExpertProject $project)
    {
        $this->authorize('view', $project);

        return MaterialResource::collection($project->materials()->latest()->get());
    }

    public function selectionLimits(ExpertProject $project)
    {
        $this->authorize('view', $project);

        return response()->json([
            'max_materials_per_message' => max(0, (int) config('expert.material_context.max_materials_per_message', 0)),
            'max_images_per_message' => max(1, (int) config('expert.vision.max_images_per_message', 4)),
        ]);
    }

    public function store(MaterialUploadRequest $request, ExpertProject $project)
    {
        $this->authorize('update', $project);

        try {
            return response()->json(new MaterialResource($this->service->store($project, (int) $request->user()->id, $request->file('file'))), 201);
        } catch (ExpertStorageQuotaException $exception) {
            $response = $exception->toApiResponse();

            return response()->json($response['body'], $response['status']);
        } catch (ExpertStorageException $exception) {
            return response()->json([
                'code' => $exception->failureCode,
                'message' => 'Хранилище файлов временно недоступно.',
            ], 503);
        }
    }

    public function show(ExpertProjectMaterial $material)
    {
        $this->authorize('view', $material->project);

        return response()->json(new MaterialResource($material));
    }

    public function content(Request $request, ExpertProjectMaterial $material)
    {
        $this->authorize('view', $material->project);
        abort_unless(str_starts_with((string) $material->mime_type, 'image/'), 404);

        return $this->privateImageResponse(
            $request,
            $this->storage->diskForMaterial($material),
            $this->storage->keyForMaterial($material),
            (string) $material->mime_type,
            (string) $material->original_name,
            $material,
        );
    }

    public function thumbnail(Request $request, ExpertProjectMaterial $material)
    {
        $this->authorize('view', $material->project);
        abort_unless(str_starts_with((string) $material->mime_type, 'image/'), 404);
        $sourceDisk = $this->storage->diskForMaterial($material);
        $sourceKey = $this->storage->keyForMaterial($material);
        abort_unless($this->storage->exists($sourceDisk, $sourceKey, $this->storage->contextForMaterial($material)), 404);

        try {
            $thumbnail = $this->thumbnails->ensure($material);
        } catch (ExpertMaterialThumbnailException) {
            return response()->json(['code' => 'material_thumbnail_unavailable', 'message' => 'Миниатюра изображения недоступна.'], 422);
        }

        return $this->privateImageResponse(
            $request,
            $this->storage->cacheDisk(),
            $thumbnail['path'],
            'image/jpeg',
            'thumbnail.jpg',
            $material,
            $thumbnail['fingerprint'],
            $thumbnail['last_modified'],
        );
    }

    public function download(ExpertProjectMaterial $material)
    {
        $this->authorize('view', $material->project);
        $disk = $this->storage->diskForMaterial($material);
        $key = $this->storage->keyForMaterial($material);
        $context = $this->storage->contextForMaterial($material);
        abort_unless($this->storage->exists($disk, $key, $context), 404);

        return $this->storage->downloadResponse($disk, $key, (string) $material->original_name, [
            'Content-Type' => (string) $material->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ], $context);
    }

    public function destroy(ExpertProjectMaterial $material)
    {
        $this->authorize('update', $material->project);
        $this->service->delete($material);

        return response()->noContent();
    }

    private function privateImageResponse(Request $request, string $disk, string $key, string $mimeType, string $name, ExpertProjectMaterial $material, ?string $fingerprint = null, ?int $lastModified = null)
    {
        $context = $this->storage->contextForMaterial($material);
        abort_unless($this->storage->exists($disk, $key, $context), 404);
        $size = $this->storage->size($disk, $key, $context);
        $lastModified ??= $this->storage->lastModified($disk, $key, $context);
        $lastModified = max($lastModified, (int) ($material->updated_at?->timestamp ?: 0), 1);
        $fingerprint ??= hash('sha256', implode('|', [$material->public_id, $disk, $key, (string) $size, (string) $lastModified, (string) ($material->updated_at?->timestamp ?: 0)]));
        $etag = '"'.$fingerprint.'"';
        $headers = [
            'Content-Type' => $mimeType,
            'Cache-Control' => config('expert.material_thumbnails.cache_control', 'private, max-age=3600, must-revalidate'),
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified).' GMT',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="'.str_replace(['\\', '"', "\r", "\n"], '_', basename($name)).'"',
            'Content-Length' => (string) $size,
        ];
        $ifNoneMatch = trim((string) $request->header('If-None-Match'));
        $notModified = $ifNoneMatch === '*' || ($ifNoneMatch !== '' && in_array($etag, array_map('trim', explode(',', $ifNoneMatch)), true));
        if (! $notModified && ($since = $request->header('If-Modified-Since')) !== null) {
            $notModified = ($parsedSince = strtotime($since)) !== false && $parsedSince >= $lastModified;
        }
        if ($notModified) {
            return response('', 304, $headers);
        }

        $response = $this->storage->streamResponse($disk, $key, $headers, $context);
        $response->setPrivate();
        $response->headers->set('Cache-Control', (string) ($headers['Cache-Control'] ?? 'private, max-age=3600, must-revalidate'));

        return $response;
    }
}
