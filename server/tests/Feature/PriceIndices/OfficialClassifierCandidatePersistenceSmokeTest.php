<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Services\FindEquivalentReadyClassifierImport;
use App\Domain\PriceIndices\Application\Services\PersistTrustedClassifierCandidate;
use App\Domain\PriceIndices\Application\Services\ResolveTrustedClassifierCandidateSource;
use App\Domain\PriceIndices\Application\Services\TrustedClassifierCandidateRegistry;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierCandidateStagingException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OfficialClassifierCandidatePersistenceSmokeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_exact_official_candidate_persists_when_prerequisites_are_available(): void
    {
        $descriptor = app(TrustedClassifierCandidateRegistry::class)->get(
            TrustedClassifierCandidateRegistry::OKPD2_145_2026,
        );

        try {
            [$classifier, $source] = app(ResolveTrustedClassifierCandidateSource::class)->resolve($descriptor);
        } catch (ClassifierCandidateStagingException $exception) {
            if ($exception->errorCode !== 'source_artifact_not_available') {
                throw $exception;
            }

            $this->markTestSkipped('prerequisite_not_available: exact official authoritative source is absent.');
        }

        $readyImport = app(FindEquivalentReadyClassifierImport::class)->find($descriptor, $source);

        if ($readyImport === null) {
            $this->markTestSkipped('prerequisite_not_available: corrected equivalent READY import is absent.');
        }

        $result = app(PersistTrustedClassifierCandidate::class)->persist(
            $descriptor->candidateKey,
            CarbonImmutable::parse('2026-08-25'),
        );

        $this->assertSame('145/2026', $result->versionLabel);
        $this->assertSame('ready', $result->status);
        $this->assertSame(20_982, $result->nodeCount);
        $this->assertSame(0, $readyImport->validation_warnings_count);
        $this->assertSame(20_982, DB::table('statistical_classifier_nodes')
            ->where('classifier_version_id', function ($query) use ($result): void {
                $query->select('id')
                    ->from('statistical_classifier_versions')
                    ->where('public_id', $result->versionPublicId);
            })
            ->count());
        $this->assertSame(21, DB::table('statistical_classifier_nodes')
            ->where('classifier_version_id', function ($query) use ($result): void {
                $query->select('id')
                    ->from('statistical_classifier_versions')
                    ->where('public_id', $result->versionPublicId);
            })
            ->whereNull('parent_node_id')
            ->count());
        $this->assertSame(1_321, DB::table('statistical_classifier_nodes')
            ->where('classifier_version_id', function ($query) use ($result): void {
                $query->select('id')
                    ->from('statistical_classifier_versions')
                    ->where('public_id', $result->versionPublicId);
            })
            ->whereNotNull('notes_text')
            ->count());
        $this->assertSame(0, DB::table('statistical_classifier_active_versions')
            ->where('classifier_id', $classifier->id)
            ->count());
    }
}
