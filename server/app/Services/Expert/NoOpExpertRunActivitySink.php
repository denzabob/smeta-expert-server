<?php

declare(strict_types=1);

namespace App\Services\Expert;

/** Keeps the legacy synchronous chat flow free of streaming trace side effects. */
final class NoOpExpertRunActivitySink implements ExpertRunActivitySink
{
    public function start(string $code, string $category, ?string $detail = null): string
    {
        return '';
    }

    public function complete(string $activityId, ?string $code = null): void {}

    public function skip(string $code, string $category, ?string $detail = null): string
    {
        return '';
    }

    public function fail(string $activityId, ?string $errorCode = null): void {}

    public function record(string $code, string $category, ?string $detail = null): string
    {
        return '';
    }

    public function terminalize(string $status): void {}

    public function isCancellationRequested(): bool
    {
        return false;
    }
}
