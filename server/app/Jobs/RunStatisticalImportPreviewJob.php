<?php

namespace App\Jobs;

use App\Domain\PriceIndices\Application\Services\BuildImportPreviewPayload;
use App\Domain\PriceIndices\Application\Services\FailStatisticalImportPreview;
use App\Domain\PriceIndices\Application\Services\PreviewStatisticalSourceFile;
use App\Domain\PriceIndices\Application\Services\StatisticalImportPreviewCacheKey;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportPreviewStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesApiException;
use App\Domain\PriceIndices\Domain\Previews\StatisticalImportPreview;
use App\Domain\PriceIndices\Domain\Previews\StatisticalImportPreviewLifecycle;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RunStatisticalImportPreviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;
    public int $timeout;

    public function __construct(public readonly string $previewPublicId)
    {
        $this->tries = (int) config('price_indices.imports.preview_job_tries', 1);
        $this->timeout = (int) config('price_indices.imports.preview_job_timeout', 180);
    }

    public function handle(
        PreviewStatisticalSourceFile $previewSourceFile,
        BuildImportPreviewPayload $payloadBuilder,
        StatisticalImportPreviewCacheKey $cacheKey,
        StatisticalImportPreviewLifecycle $lifecycle,
        FailStatisticalImportPreview $fail,
    ): void {
        $preview = StatisticalImportPreview::query()
            ->with('sourceFile')
            ->where('public_id', $this->previewPublicId)
            ->firstOrFail();
        if ($preview->status !== StatisticalImportPreviewStatus::Pending) {
            Log::info('Price indices preview job skipped because preview is not pending.', $this->context($preview));
            return;
        }

        $lock = Cache::lock(
            $cacheKey->lockName($preview->cache_key),
            (int) config('price_indices.imports.preview_lock_ttl', 300),
        );
        if (! $lock->get()) {
            Log::info('Price indices preview job skipped because equivalent preview is locked.', $this->context($preview));
            return;
        }

        $startedAt = microtime(true);
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        try {
            $preview = DB::transaction(function () use ($preview, $lifecycle): StatisticalImportPreview {
                $target = StatisticalImportPreview::query()
                    ->with('sourceFile')
                    ->lockForUpdate()
                    ->findOrFail($preview->id);
                if ($target->status !== StatisticalImportPreviewStatus::Pending) {
                    return $target;
                }

                $lifecycle->transition($target, StatisticalImportPreviewStatus::Running);
                $target->started_at = now();
                $target->save();

                return $target->refresh()->load('sourceFile');
            });
            if ($preview->status !== StatisticalImportPreviewStatus::Running) {
                return;
            }

            $result = $previewSourceFile->execute($preview->sourceFile);
            $payload = $payloadBuilder->execute($preview->sourceFile, $result);
            $serialized = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $finishedAt = now();
            $elapsedSeconds = microtime(true) - $startedAt;
            $peakMemoryBytes = memory_get_peak_usage(true);

            DB::transaction(function () use (
                $preview,
                $result,
                $payload,
                $serialized,
                $finishedAt,
                $elapsedSeconds,
                $peakMemoryBytes,
                $lifecycle,
            ): void {
                $target = StatisticalImportPreview::query()->lockForUpdate()->findOrFail($preview->id);
                if ($target->status !== StatisticalImportPreviewStatus::Running) {
                    return;
                }

                $lifecycle->transition($target, StatisticalImportPreviewStatus::Ready);
                $target->forceFill([
                    'finished_at' => $finishedAt,
                    'expires_at' => $finishedAt->copy()->addHours(
                        (int) config('price_indices.imports.preview_cache_ttl_hours', 24)
                    ),
                    'sheets_total' => (int) $result->structure['sheets_total'],
                    'supported_sheets' => count($result->structure['supported_sheets']),
                    'ignored_sheets' => count($result->structure['ignored_sheets']),
                    'commodity_occurrences' => (int) $result->counts['commodity_occurrences'],
                    'unique_classifier_items' => (int) $result->counts['unique_commodity_codes'],
                    'observation_candidates' => (int) $result->counts['observation_candidates'],
                    'numeric_count' => (int) $result->counts['numeric'],
                    'missing_count' => (int) $result->counts['missing'],
                    'footnoted_count' => (int) $result->counts['special_footnoted'],
                    'warnings_count' => (int) $result->counts['warnings'],
                    'fatal_errors_count' => (int) $result->counts['fatal_errors'],
                    'result_json' => $payload,
                    'metadata_json' => [
                        'elapsed_seconds' => $elapsedSeconds,
                        'peak_memory_bytes' => $peakMemoryBytes,
                        'result_json_bytes' => strlen($serialized),
                    ],
                ])->save();
            });

            Log::info('Price indices preview ready.', $this->context($preview) + [
                'elapsed_seconds' => $elapsedSeconds,
                'peak_memory_bytes' => $peakMemoryBytes,
                'result_json_bytes' => strlen($serialized),
            ]);
        } catch (PriceIndicesApiException $exception) {
            $fail->execute($preview, $exception->errorCode, $exception->getMessage());
            if (in_array($exception->errorCode, ['preview_failed', 'preview_internal_error'], true)) {
                throw $exception->getPrevious() ?? $exception;
            }
        } catch (Throwable $exception) {
            $fail->execute(
                $preview,
                'preview_internal_error',
                'Unexpected statistical preview failure.',
            );
            Log::error('Price indices preview failed unexpectedly.', $this->context($preview) + [
                'exception' => $exception::class,
            ]);
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $preview = StatisticalImportPreview::query()
            ->where('public_id', $this->previewPublicId)
            ->first();
        if ($preview !== null && in_array($preview->status, [
            StatisticalImportPreviewStatus::Pending,
            StatisticalImportPreviewStatus::Running,
        ], true)) {
            app(FailStatisticalImportPreview::class)->execute(
                $preview,
                'preview_internal_error',
                'Unexpected statistical preview failure.',
            );
        }
    }

    /** @return array<string, mixed> */
    private function context(StatisticalImportPreview $preview): array
    {
        return [
            'preview_public_id' => $preview->public_id,
            'source_file_public_id' => $preview->sourceFile()->value('public_id'),
            'importer_code' => $preview->importer_code,
            'importer_version' => $preview->importer_version,
            'cache_key' => $preview->cache_key,
        ];
    }
}
