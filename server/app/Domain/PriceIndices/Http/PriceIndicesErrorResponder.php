<?php

namespace App\Domain\PriceIndices\Http;

use App\Domain\PriceIndices\Domain\Enums\SourceFileErrorCode;
use App\Domain\PriceIndices\Domain\Exceptions\DatasetCodeImmutable;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Exceptions\SourceFileActivationConflict;
use App\Domain\PriceIndices\Domain\Exceptions\SourceFileDuplicate;
use App\Domain\PriceIndices\Domain\Exceptions\SourceFileIngestionException;
use App\Domain\PriceIndices\Domain\Exceptions\SourceFileStorageException;
use App\Domain\PriceIndices\Domain\Exceptions\SourceFileTransitionNotAllowed;
use App\Domain\PriceIndices\Domain\Exceptions\XlsxValidationException;
use Illuminate\Http\JsonResponse;
use Throwable;

class PriceIndicesErrorResponder
{
    public function respond(Throwable $exception): JsonResponse
    {
        if ($exception instanceof SourceFileDuplicate) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => SourceFileErrorCode::DuplicateFile->value,
                'existing_file' => [
                    'public_id' => $exception->existingFile->public_id,
                    'status' => $exception->existingFile->status->value,
                    'reporting_year' => $exception->existingFile->reporting_year,
                    'reporting_month' => $exception->existingFile->reporting_month,
                ],
            ], 409);
        }

        if ($exception instanceof DatasetCodeImmutable) {
            return $this->error($exception->getMessage(), SourceFileErrorCode::ImmutableDatasetCode, 409);
        }

        if ($exception instanceof SourceFileTransitionNotAllowed
            || $exception instanceof SourceFileActivationConflict
        ) {
            return $this->error($exception->getMessage(), SourceFileErrorCode::InvalidLifecycle, 409);
        }

        if ($exception instanceof XlsxValidationException
            || $exception instanceof SourceFileIngestionException
        ) {
            return $this->error($exception->getMessage(), $exception->errorCode, 422);
        }

        if ($exception instanceof PriceIndicesInvariantViolation) {
            return $this->error($exception->getMessage(), SourceFileErrorCode::InvalidPeriod, 422);
        }

        if ($exception instanceof SourceFileStorageException) {
            return $this->error(
                'Unable to store or read the source file.',
                SourceFileErrorCode::StorageFailure,
                500
            );
        }

        throw $exception;
    }

    private function error(
        string $message,
        SourceFileErrorCode $code,
        int $status
    ): JsonResponse {
        return response()->json([
            'message' => $message,
            'code' => $code->value,
        ], $status);
    }
}
