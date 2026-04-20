<?php

namespace App\Services;

use App\Evidence\CaptureMethod;
use App\Evidence\CostComponent;
use App\Evidence\SourceType;
use App\Evidence\VerificationStatus as EvidenceVerificationStatus;
use App\Models\EvidenceRecord;
use App\Models\LaborEvidenceSource;
use App\Models\User;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LaborEvidenceSourceService
{
    public function __construct(
        private readonly UrlNormalizer $urlNormalizer,
    ) {}

    public function create(User $user, array $attributes): LaborEvidenceSource
    {
        $this->assertLaborProfilePresent($attributes, $user->id, 'create');

        return DB::transaction(function () use ($user, $attributes) {
            $evidenceRecord = $this->createEvidenceRecord($user, $attributes);

            $source = LaborEvidenceSource::create([
                ...$attributes,
                'user_id' => $user->id,
                'evidence_record_id' => $evidenceRecord->id,
            ]);

            return $source->load($this->relations());
        });
    }

    public function update(LaborEvidenceSource $source, array $attributes): LaborEvidenceSource
    {
        $this->assertLaborProfilePresent($attributes + ['labor_profile_id' => $source->labor_profile_id], $source->user_id, 'update');

        return DB::transaction(function () use ($source, $attributes) {
            $source->fill($attributes);
            $source->save();

            $record = $source->evidenceRecord;
            if (!$record) {
                $record = $this->createEvidenceRecord($source->user, $source->toArray());
                $source->evidence_record_id = $record->id;
                $source->save();
            } else {
                $record->update($this->buildEvidenceRecordPayload($source->user, $source->toArray()));
            }

            return $source->load($this->relations());
        });
    }

    public function relations(): array
    {
        return [
            'region',
            'provider',
            'laborProfile',
            'evidenceRecord.assets',
        ];
    }

    private function createEvidenceRecord(User $user, array $attributes): EvidenceRecord
    {
        return EvidenceRecord::create($this->buildEvidenceRecordPayload($user, $attributes) + [
            'uuid' => (string) Str::uuid(),
            'cost_component' => CostComponent::LABOR_WORK,
            'created_by' => $user->id,
        ]);
    }

    private function buildEvidenceRecordPayload(User $user, array $attributes): array
    {
        $normalizedUrl = $this->urlNormalizer->normalize($attributes['source_url'] ?? null);
        $observedAt = $this->resolveObservedAt($attributes['source_date'] ?? null);

        return [
            'source_type' => $this->mapSourceType($attributes['captured_via'] ?? 'manual'),
            'capture_method' => $this->mapCaptureMethod($attributes['captured_via'] ?? 'manual'),
            'verification_status' => $this->mapVerificationStatus($attributes['verification_status'] ?? 'pending'),
            'source_url' => $normalizedUrl,
            'source_domain' => $this->extractDomain($normalizedUrl),
            'observed_price' => $this->resolveObservedPrice($attributes),
            'currency' => strtoupper((string) ($attributes['currency'] ?? 'RUB')),
            'observed_at' => $observedAt,
            'extracted_name' => $attributes['vacancy_title'] ?? $attributes['source_title'] ?? null,
            'metadata_json' => null,
        ];
    }

    private function resolveObservedAt(mixed $sourceDate): Carbon
    {
        if ($sourceDate instanceof Carbon) {
            return $sourceDate;
        }

        if (is_string($sourceDate) && $sourceDate !== '') {
            return Carbon::parse($sourceDate);
        }

        return now();
    }

    private function resolveObservedPrice(array $attributes): ?float
    {
        $value = $attributes['derived_hourly_rate']
            ?? $attributes['salary_value']
            ?? $attributes['salary_value_min']
            ?? $attributes['salary_value_max']
            ?? null;

        return $value !== null ? (float) $value : null;
    }

    private function extractDomain(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return $host ? strtolower((string) $host) : null;
    }

    private function mapSourceType(string $capturedVia): string
    {
        return match ($capturedVia) {
            'chrome' => SourceType::CHROME_CAPTURE,
            'import' => SourceType::SUPPLIER_WEBSITE,
            default => SourceType::MANUAL_INPUT,
        };
    }

    private function mapCaptureMethod(string $capturedVia): string
    {
        return match ($capturedVia) {
            'chrome' => CaptureMethod::CHROME_EXTENSION,
            'import' => CaptureMethod::API_IMPORT,
            default => CaptureMethod::MANUAL_ENTRY,
        };
    }

    private function mapVerificationStatus(string $verificationStatus): string
    {
        return match ($verificationStatus) {
            'verified' => EvidenceVerificationStatus::MANUAL_VERIFIED,
            'rejected' => EvidenceVerificationStatus::REJECTED,
            default => EvidenceVerificationStatus::PENDING,
        };
    }

    private function assertLaborProfilePresent(array $attributes, int $userId, string $operation): void
    {
        $laborProfileId = $attributes['labor_profile_id'] ?? null;

        if ($laborProfileId !== null && (int) $laborProfileId > 0) {
            return;
        }

        Log::warning('Rejected labor evidence source write without labor profile', [
            'operation' => $operation,
            'user_id' => $userId,
        ]);

        throw new DomainException('Labor profile must be selected for evidence source.');
    }
}
