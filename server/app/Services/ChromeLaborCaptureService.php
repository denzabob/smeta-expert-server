<?php

namespace App\Services;

use App\Evidence\CaptureMethod;
use App\Evidence\CostComponent;
use App\Evidence\SourceType;
use App\Evidence\VerificationStatus as EvidenceVerificationStatus;
use App\Models\EvidenceRecord;
use App\Models\LaborEvidenceSource;
use App\Models\LaborProvider;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ChromeLaborCaptureService
{
    public function __construct(
        private readonly UrlNormalizer $urlNormalizer,
        private readonly LaborEvidenceAssetService $assetService,
    ) {}

    public function capture(User $user, array $payload, UploadedFile $screenshot): array
    {
        return DB::transaction(function () use ($user, $payload, $screenshot) {
            $normalizedUrl = $this->urlNormalizer->normalize($payload['source_url']);
            $provider = $this->resolveProvider($user, $payload, $normalizedUrl);
            $regionId = $this->resolveRegionId($user, $payload);
            $laborProfileId = $this->resolveLaborProfileId($payload);

            $evidenceRecord = EvidenceRecord::create([
                'uuid' => (string) Str::uuid(),
                'cost_component' => CostComponent::LABOR_WORK,
                'source_type' => SourceType::CHROME_CAPTURE,
                'capture_method' => 'chrome_labor_capture',
                'verification_status' => EvidenceVerificationStatus::PENDING,
                'source_url' => $normalizedUrl,
                'source_domain' => $this->extractDomain($normalizedUrl),
                'observed_price' => $this->resolveObservedPrice($payload),
                'currency' => strtoupper((string) ($payload['currency'] ?? 'RUB')),
                'observed_at' => $this->resolveObservedAt($payload['source_date'] ?? null),
                'extracted_name' => $payload['vacancy_title'] ?? $payload['source_title'] ?? null,
                'metadata_json' => $this->buildMetadata($payload),
                'created_by' => $user->id,
            ]);

            $source = LaborEvidenceSource::create([
                'user_id' => $user->id,
                'region_id' => $regionId,
                'labor_profile_id' => $laborProfileId,
                'provider_id' => $provider->id,
                'evidence_record_id' => $evidenceRecord->id,
                'source_title' => $payload['source_title'] ?? null,
                'source_url' => $normalizedUrl,
                'source_date' => $payload['source_date'] ?? null,
                'employer_name' => $payload['employer_name'] ?? null,
                'vacancy_title' => $payload['vacancy_title'] ?? null,
                'vacancy_description' => $payload['vacancy_description'] ?? null,
                'vacancy_excerpt' => $this->buildVacancyExcerpt($payload),
                'salary_raw_text' => $payload['salary_raw_text'] ?? null,
                'salary_value' => $payload['salary_value'] ?? null,
                'salary_value_min' => $payload['salary_value_min'] ?? null,
                'salary_value_max' => $payload['salary_value_max'] ?? null,
                'salary_period' => $payload['salary_period'] ?? null,
                'hours_per_month' => $payload['hours_per_month'] ?? 160,
                'derived_hourly_rate' => $payload['derived_hourly_rate'] ?? null,
                'currency' => strtoupper((string) ($payload['currency'] ?? 'RUB')),
                'note' => $payload['note'] ?? null,
                'captured_via' => 'chrome',
                'verification_status' => 'pending',
                'is_active' => true,
            ]);

            $asset = $this->assetService->store(
                $source->load('evidenceRecord'),
                $screenshot,
                'screenshot',
                (int) $user->id,
            );

            $source = $source->load([
                'region',
                'provider',
                'laborProfile',
                'evidenceRecord.assets',
            ]);

            return [
                'labor_evidence_source' => $source,
                'evidence_record' => $source->evidenceRecord,
                'assets' => [$asset],
            ];
        });
    }

    private function resolveProvider(User $user, array $payload, string $normalizedUrl): LaborProvider
    {
        $domain = $this->normalizeDomain($payload['provider_domain'] ?? null)
            ?? $this->normalizeDomain($this->extractDomain($normalizedUrl));

        if (!$domain) {
            throw new HttpException(422, 'Provider domain could not be resolved.');
        }

        $existing = LaborProvider::query()
            ->ownedBy((int) $user->id)
            ->where('domain', $domain)
            ->first();

        if ($existing) {
            return $existing;
        }

        return LaborProvider::create([
            'user_id' => $user->id,
            'title' => $payload['provider_title'] ?? $domain,
            'domain' => $domain,
            'base_url' => $this->buildBaseUrl($normalizedUrl, $domain),
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function resolveRegionId(User $user, array $payload): int
    {
        $regionId = isset($payload['region_id']) ? (int) $payload['region_id'] : (int) ($user->settings?->region_id ?? 0);

        if ($regionId <= 0) {
            throw new HttpException(422, 'Region is required for labor capture.');
        }

        return $regionId;
    }

    private function resolveLaborProfileId(array $payload): ?int
    {
        return isset($payload['labor_profile_id']) ? (int) $payload['labor_profile_id'] : null;
    }

    private function resolveObservedPrice(array $payload): ?float
    {
        $value = $payload['derived_hourly_rate']
            ?? $payload['salary_value']
            ?? $payload['salary_value_min']
            ?? $payload['salary_value_max']
            ?? null;

        return $value !== null ? (float) $value : null;
    }

    private function resolveObservedAt(mixed $sourceDate): Carbon
    {
        if (is_string($sourceDate) && $sourceDate !== '') {
            return Carbon::parse($sourceDate);
        }

        return now();
    }

    private function extractDomain(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return $host ? strtolower((string) $host) : null;
    }

    private function normalizeDomain(?string $domain): ?string
    {
        if (!$domain) {
            return null;
        }

        $normalized = strtolower(trim($domain));
        $normalized = preg_replace('/^https?:\/\//', '', $normalized);
        $normalized = preg_replace('/^www\./', '', $normalized);
        $normalized = rtrim($normalized, '/');

        return $normalized !== '' ? $normalized : null;
    }

    private function buildBaseUrl(string $normalizedUrl, string $domain): string
    {
        $scheme = parse_url($normalizedUrl, PHP_URL_SCHEME) ?: 'https';

        return $scheme . '://' . $domain;
    }

    private function buildVacancyExcerpt(array $payload): ?string
    {
        $description = trim((string) ($payload['vacancy_description'] ?? ''));
        if ($description === '') {
            return null;
        }

        return mb_substr($description, 0, 500);
    }

    private function buildMetadata(array $payload): ?array
    {
        $meta = [];
        foreach (['capture_mode', 'browser_context_json', 'selectors_json'] as $key) {
            if (!empty($payload[$key])) {
                $meta[$key] = $payload[$key];
            }
        }

        return $meta !== [] ? $meta : null;
    }
}
