<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinishedProductPriceEvidenceAsset;
use App\Models\FinishedProductPriceSource;
use App\Services\FinishedProductPriceEvidenceAssetAccessService;
use App\Services\FinishedProductSpecificationAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinishedProductPriceEvidenceAssetController extends Controller
{
    public function __construct(
        private FinishedProductSpecificationAccessService $accessService,
        private FinishedProductPriceEvidenceAssetAccessService $assetAccessService,
    ) {}

    public function index(Request $request, FinishedProductPriceSource $source): JsonResponse
    {
        $source = $this->accessService->resolveOwnedSource((int) $request->user()->id, $source);

        $assets = $source->evidenceAssets()
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'finished_product_price_source_id' => $source->id,
            'assets' => $assets->map(fn (FinishedProductPriceEvidenceAsset $asset) => $this->formatAsset($asset))
                ->values()
                ->all(),
        ]);
    }

    public function store(Request $request, FinishedProductPriceSource $source): JsonResponse
    {
        $source = $this->accessService->resolveOwnedSource((int) $request->user()->id, $source);

        $request->merge([
            'source_url' => $this->normalizeSubmittedUrl($request->input('source_url')),
        ]);

        $validated = $request->validate([
            'asset_type' => 'required|in:screenshot,file,image,link',
            'file' => 'nullable|file|max:20480',
            'source_url' => 'nullable|string|max:2000',
            'content_hash' => 'nullable|string|max:128',
            'captured_at' => 'nullable|date',
            'metadata' => 'nullable|array',
        ], [
            'asset_type.required' => 'Выберите тип доказательства.',
            'asset_type.in' => 'Выбран неподдерживаемый тип доказательства.',
            'file.file' => 'Выберите корректный файл.',
            'file.max' => 'Размер файла не должен превышать 20 МБ.',
            'source_url.max' => 'Ссылка слишком длинная.',
        ]);

        if ($validated['asset_type'] === FinishedProductPriceEvidenceAsset::TYPE_LINK) {
            if (empty($validated['source_url'])) {
                return response()->json([
                    'message' => 'Проверьте данные формы.',
                    'errors' => [
                        'source_url' => ['Ссылка обязательна для доказательства-ссылки.'],
                    ],
                ], 422);
            }

            if (!$this->isValidHttpUrl((string) $validated['source_url'])) {
                return response()->json([
                    'message' => 'Проверьте данные формы.',
                    'errors' => [
                        'source_url' => ['Введите корректную ссылку.'],
                    ],
                ], 422);
            }
        } elseif (!$request->hasFile('file')) {
            return response()->json([
                'message' => 'Проверьте данные формы.',
                'errors' => [
                    'file' => ['Выберите файл, изображение или вставьте скриншот.'],
                ],
            ], 422);
        }

        $payload = [
            'asset_type' => $validated['asset_type'],
            'source_url' => $validated['source_url'] ?? null,
            'content_hash' => $validated['content_hash'] ?? null,
            'captured_at' => $validated['captured_at'] ?? now(),
            'metadata' => $validated['metadata'] ?? null,
        ];

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $directory = "finished-product-evidence/{$request->user()->id}/{$source->id}";
            $extension = $file->getClientOriginalExtension();
            $filename = Str::uuid()->toString() . ($extension ? '.' . $extension : '');
            $filePath = $file->storeAs($directory, $filename, 'public');

            $payload['file_path'] = $filePath;
            $payload['original_name'] = $file->getClientOriginalName();
            $payload['mime_type'] = $file->getMimeType() ?: $file->getClientMimeType();
            $payload['file_size'] = $file->getSize();
            $payload['content_hash'] = hash_file('sha256', $file->getRealPath());
        }

        $asset = $source->evidenceAssets()->create($payload);

        return response()->json($this->formatAsset($asset), 201);
    }

    private function normalizeSubmittedUrl(mixed $value): ?string
    {
        $url = trim((string) ($value ?? ''));
        if ($url === '') {
            return null;
        }

        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        return $url;
    }

    private function isValidHttpUrl(string $url): bool
    {
        if (preg_match('/\s/u', $url)) {
            return false;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        return in_array($scheme, ['http', 'https'], true) && $host !== '';
    }

    public function destroy(Request $request, FinishedProductPriceEvidenceAsset $asset): JsonResponse
    {
        $asset = $this->accessService->resolveOwnedEvidenceAsset((int) $request->user()->id, $asset);

        if ($asset->file_path) {
            Storage::disk('public')->delete($asset->file_path);
        }

        $asset->delete();

        return response()->json(null, 204);
    }

    public function open(Request $request, FinishedProductPriceEvidenceAsset $asset): StreamedResponse|Response
    {
        $asset = $this->accessService->resolveOwnedEvidenceAsset((int) $request->user()->id, $asset);
        $source = $asset->source;
        if (!$source) {
            abort(404, 'Источник не найден.');
        }

        if ($asset->source_url) {
            return redirect()->away($asset->source_url);
        }

        $filePath = $asset->file_path ? trim((string) $asset->file_path) : null;
        if (!$filePath) {
            abort(404, 'Файл доказательства недоступен.');
        }

        $disk = Storage::disk('public');
        if (!$disk->exists($filePath)) {
            abort(404, 'Файл не найден.');
        }

        $filename = $asset->original_name ?: basename($filePath);
        $mimeType = $asset->mime_type ?: null;
        $isDownload = $request->boolean('download');
        $inlinePreview = !$isDownload && $this->canPreviewInline($asset->asset_type, $mimeType, $filePath);
        $disposition = $inlinePreview ? 'inline' : 'attachment';

        return $disk->response(
            $filePath,
            $filename,
            array_filter([
                'Content-Disposition' => $disposition . '; filename="' . addslashes($filename) . '"',
                'Content-Type' => $mimeType,
            ]),
        );
    }

    private function canPreviewInline(?string $assetType, ?string $mimeType, string $filePath): bool
    {
        if ($mimeType !== null) {
            if (str_starts_with($mimeType, 'image/')) {
                return true;
            }

            if ($mimeType === 'application/pdf') {
                return true;
            }
        }

        if (in_array($assetType, ['screenshot', 'image'], true)) {
            return true;
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg', 'pdf'], true);
    }

    private function formatAsset(FinishedProductPriceEvidenceAsset $asset): array
    {
        $access = $this->assetAccessService->describe($asset);

        return [
            'id' => $asset->id,
            'asset_type' => $asset->asset_type,
            'file_path' => $asset->file_path,
            'original_name' => $asset->original_name,
            'mime_type' => $asset->mime_type,
            'file_size' => $asset->file_size,
            'source_url' => $asset->source_url,
            'content_hash' => $asset->content_hash,
            'captured_at' => $asset->captured_at?->toIso8601String(),
            'metadata' => $asset->metadata ?? [],
            'can_preview' => $access['can_preview'],
            'can_download' => $access['can_download'],
            'preview_url' => $access['preview_url'],
            'download_url' => $access['download_url'],
            'open_url' => $access['open_url'],
            'access_kind' => $access['access_kind'],
        ];
    }
}
