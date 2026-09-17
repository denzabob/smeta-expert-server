<?php

declare(strict_types=1);

namespace App\Services\Expert;

/**
 * Operational trace boundary for an Expert generation run.
 *
 * Implementations must never treat activity detail as document content or
 * provider metadata. The synchronous chat path intentionally uses a no-op
 * implementation.
 */
interface ExpertRunActivitySink
{
    public function start(string $code, string $category, ?string $detail = null): string;

    public function complete(string $activityId, ?string $code = null): void;

    public function skip(string $code, string $category, ?string $detail = null): string;

    public function fail(string $activityId, ?string $errorCode = null): void;

    public function record(string $code, string $category, ?string $detail = null): string;

    /** Complete every pending operation with a non-success terminal status. */
    public function terminalize(string $status): void;

    public function isCancellationRequested(): bool;
}
