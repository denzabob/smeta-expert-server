<?php

namespace App\Domain\PriceIndices\Http\Controllers;

use App\Domain\PriceIndices\Application\Data\IngestSourceFileData;
use App\Domain\PriceIndices\Application\Services\IngestSourceFile;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\AcquisitionMethod;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Exceptions\SourceFileActivationConflict;
use App\Domain\PriceIndices\Domain\Exceptions\SourceFileDuplicate;
use App\Domain\PriceIndices\Domain\Exceptions\SourceFileIngestionException;
use App\Domain\PriceIndices\Domain\Exceptions\SourceFileStorageException;
use App\Domain\PriceIndices\Domain\Exceptions\SourceFileTransitionNotAllowed;
use App\Domain\PriceIndices\Domain\Exceptions\XlsxValidationException;
use App\Domain\PriceIndices\Domain\SourceFiles\ActivateSourceFile;
use App\Domain\PriceIndices\Domain\SourceFiles\ApproveSourceFile;
use App\Domain\PriceIndices\Domain\SourceFiles\RejectSourceFile;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use App\Domain\PriceIndices\Domain\Sources\StatisticalSource;
use App\Domain\PriceIndices\Http\PriceIndicesErrorResponder;
use App\Domain\PriceIndices\Http\Requests\RejectSourceFileRequest;
use App\Domain\PriceIndices\Http\Requests\SourceFileActionRequest;
use App\Domain\PriceIndices\Http\Requests\SourceFileIndexRequest;
use App\Domain\PriceIndices\Http\Requests\UploadSourceFileRequest;
use App\Domain\PriceIndices\Http\Resources\SourceFileResource;
use App\Domain\PriceIndices\Infrastructure\Storage\PrivateSourceFileStorage;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class SourceFileAdminController extends Controller
{
    /** @var list<string> */
    private const RELATIONS = [
        'dataset', 'source', 'reviewedBy', 'activatedBy', 'supersedes', 'activePointer',
    ];

    public function __construct(
        private readonly PrivateSourceFileStorage $storage,
        private readonly IngestSourceFile $ingest,
        private readonly ApproveSourceFile $approveSourceFile,
        private readonly RejectSourceFile $rejectSourceFile,
        private readonly ActivateSourceFile $activateSourceFile,
        private readonly PriceIndicesErrorResponder $errors,
    ) {
    }

    public function index(SourceFileIndexRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $query = StatisticalSourceFile::query()->with(self::RELATIONS);

        if (isset($validated['dataset'])) {
            $query->whereHas('dataset', fn ($builder) => $builder->where('public_id', $validated['dataset']));
        }

        if (isset($validated['source'])) {
            $query->whereHas('source', fn ($builder) => $builder->where('public_id', $validated['source']));
        }

        foreach (['status', 'validation_status', 'acquisition_method', 'reporting_year', 'reporting_month'] as $filter) {
            if (isset($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        $query->orderBy($validated['sort'] ?? 'detected_at', $validated['direction'] ?? 'desc');

        return SourceFileResource::collection($query->paginate($validated['per_page'] ?? 25));
    }

    public function upload(UploadSourceFileRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $dataset = StatisticalDataset::query()
            ->where('public_id', $validated['dataset_public_id'])
            ->firstOrFail();
        $source = isset($validated['source_public_id'])
            ? StatisticalSource::query()->where('public_id', $validated['source_public_id'])->firstOrFail()
            : null;

        try {
            $temporaryPath = $this->storage->storeTemporaryUpload($request->file('file'));
            $sourceFile = $this->ingest->execute(new IngestSourceFileData(
                dataset: $dataset,
                source: $source,
                acquisitionMethod: AcquisitionMethod::ManualUpload,
                reportingYear: $validated['reporting_year'] ?? null,
                reportingMonth: $validated['reporting_month'] ?? null,
                sourceUrl: $validated['source_url'] ?? null,
                originalFilename: $request->file('file')->getClientOriginalName(),
                temporaryFilePath: $temporaryPath,
                mimeType: $request->file('file')->getMimeType(),
                actor: $request->user(),
                metadata: isset($validated['comment']) ? ['comment' => $validated['comment']] : null,
            ));

            return (new SourceFileResource(
                $sourceFile->load(self::RELATIONS)
            ))->response()->setStatusCode(201);
        } catch (SourceFileDuplicate|SourceFileIngestionException|SourceFileStorageException|XlsxValidationException $exception) {
            return $this->errors->respond($exception);
        }
    }

    public function show(StatisticalSourceFile $sourceFile): SourceFileResource
    {
        return new SourceFileResource($sourceFile->load(self::RELATIONS));
    }

    public function approve(
        SourceFileActionRequest $request,
        StatisticalSourceFile $sourceFile
    ): SourceFileResource|JsonResponse {
        try {
            $sourceFile = $this->approveSourceFile->execute($sourceFile, $request->user());
            Log::info('source file approved', $this->logContext($sourceFile));

            return new SourceFileResource($sourceFile->load(self::RELATIONS));
        } catch (SourceFileTransitionNotAllowed $exception) {
            return $this->errors->respond($exception);
        }
    }

    public function reject(
        RejectSourceFileRequest $request,
        StatisticalSourceFile $sourceFile
    ): SourceFileResource|JsonResponse {
        try {
            $sourceFile = $this->rejectSourceFile->execute(
                $sourceFile,
                $request->user(),
                $request->validated('reason')
            );
            Log::info('source file rejected', $this->logContext($sourceFile));

            return new SourceFileResource($sourceFile->load(self::RELATIONS));
        } catch (SourceFileTransitionNotAllowed|PriceIndicesInvariantViolation $exception) {
            return $this->errors->respond($exception);
        }
    }

    public function activate(
        SourceFileActionRequest $request,
        StatisticalSourceFile $sourceFile
    ): SourceFileResource|JsonResponse {
        try {
            $sourceFile = $this->activateSourceFile->execute($sourceFile, $request->user());
            Log::info('source file activated', $this->logContext($sourceFile));

            return new SourceFileResource($sourceFile->load(self::RELATIONS));
        } catch (SourceFileTransitionNotAllowed|SourceFileActivationConflict|PriceIndicesInvariantViolation $exception) {
            return $this->errors->respond($exception);
        }
    }

    public function download(StatisticalSourceFile $sourceFile): StreamedResponse|JsonResponse
    {
        try {
            if (! $this->storage->exists($sourceFile->stored_path)) {
                return response()->json(['message' => 'Source file binary not found.'], 404);
            }

            if ($this->storage->size($sourceFile->stored_path) > (int) config('price_indices.source_files.max_download_bytes')) {
                return response()->json([
                    'message' => 'Source file exceeds the download limit.',
                    'code' => 'file_too_large',
                ], 413);
            }
        } catch (Throwable $exception) {
            Log::error('source file storage failure', $this->logContext($sourceFile) + [
                'exception' => $exception::class,
            ]);

            return $this->errors->respond(new SourceFileStorageException(
                'Unable to read the source file.',
                $exception
            ));
        }

        $filename = $this->safeDownloadFilename($sourceFile);
        $mimeType = $sourceFile->mime_type
            ?: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return $this->storage->download(
            $sourceFile->stored_path,
            $filename,
            ['Content-Type' => $mimeType]
        );
    }

    private function safeDownloadFilename(StatisticalSourceFile $sourceFile): string
    {
        $filename = basename(str_replace('\\', '/', $sourceFile->original_filename));
        $filename = preg_replace('/[\x00-\x1F\x7F"]/', '_', $filename) ?: '';

        if ($filename === '' || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'xlsx') {
            return "source-file-{$sourceFile->public_id}.xlsx";
        }

        return $filename;
    }

    /**
     * @return array<string, mixed>
     */
    private function logContext(StatisticalSourceFile $sourceFile): array
    {
        $sourceFile->loadMissing(['dataset', 'source']);

        return [
            'dataset_public_id' => $sourceFile->dataset->public_id,
            'dataset_code' => $sourceFile->dataset->code,
            'source_public_id' => $sourceFile->source?->public_id,
            'source_file_public_id' => $sourceFile->public_id,
            'acquisition_method' => $sourceFile->acquisition_method->value,
            'reporting_year' => $sourceFile->reporting_year,
            'reporting_month' => $sourceFile->reporting_month,
            'status' => $sourceFile->status->value,
        ];
    }
}
