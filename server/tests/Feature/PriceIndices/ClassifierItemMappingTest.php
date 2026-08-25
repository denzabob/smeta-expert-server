<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Services\GenerateClassifierItemMappings;
use App\Domain\PriceIndices\Application\Services\ReportClassifierItemMappings;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifier;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierActiveVersion;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItemMapping;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierNode;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierVersion;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\ClassifierItemMappingReviewStatus;
use App\Domain\PriceIndices\Domain\Enums\ClassifierItemMappingType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClassifierItemMappingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_mapping_schema_enforces_version_scope_and_composite_node_membership(): void
    {
        [$classifier, $versionA] = $this->activeClassifier();
        $versionB = StatisticalClassifierVersion::factory()->for($classifier, 'classifier')->create();
        $item = $this->localItem('31.02.10.140', 'Наборы кухонной мебели');
        $nodeB = $this->node($versionB, $item->item_code, $item->name);

        $this->expectException(QueryException::class);
        DB::table('statistical_classifier_item_mappings')->insert([
            'public_id' => fake()->uuid(),
            'statistical_classifier_item_id' => $item->id,
            'classifier_version_id' => $versionA->id,
            'classifier_node_id' => $nodeB->id,
            'mapping_type' => 'exact',
            'review_status' => 'confirmed',
            'method' => 'manual:review',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_generator_applies_deterministic_policy_without_name_only_or_parent_guessing(): void
    {
        [, $version] = $this->activeClassifier();
        $exact = $this->localItem('31.02.10.140', "  НАБОРЫ\u{00A0}кухонной мебели ");
        $conflict = $this->localItem('31.02.10.141', 'Другое локальное название');
        $absent = $this->localItem('31.02.10.999', 'Имя существующего другого узла');
        $local = $this->localItem('05.10.10.101.АГ', 'Уголь местной классификации');
        $exactNode = $this->node($version, $exact->item_code, 'Наборы кухонной мебели');
        $conflictNode = $this->node($version, $conflict->item_code, 'Официальное название');
        $this->node($version, '31.02.10.998', $absent->name);

        $result = app(GenerateClassifierItemMappings::class)->execute('okpd2');

        $this->assertSame(4, $result->totalCompatibleItems);
        $this->assertSame(1, $result->exactConfirmed);
        $this->assertSame(1, $result->ambiguousNeedsReview);
        $this->assertSame(1, $result->localRosstat);
        $this->assertSame(1, $result->unmapped);
        $this->assertMapping($exact, $version, ClassifierItemMappingType::Exact, ClassifierItemMappingReviewStatus::Confirmed, $exactNode->id);
        $this->assertMapping($conflict, $version, ClassifierItemMappingType::Ambiguous, ClassifierItemMappingReviewStatus::NeedsReview, $conflictNode->id);
        $this->assertMapping($absent, $version, ClassifierItemMappingType::Unmapped, ClassifierItemMappingReviewStatus::Proposed, null);
        $this->assertMapping($local, $version, ClassifierItemMappingType::LocalRosstat, ClassifierItemMappingReviewStatus::Confirmed, null);
    }

    public function test_generation_is_idempotent_and_preserves_operator_owned_confirmed_and_rejected_rows(): void
    {
        [, $version] = $this->activeClassifier();
        $automaticItem = $this->localItem('31.02.10.140', 'Наборы кухонной мебели');
        $confirmedItem = $this->localItem('31.02.10.141', 'Шкафы кухонные');
        $rejectedItem = $this->localItem('31.02.10.142', 'Столы кухонные');
        $automaticNode = $this->node($version, $automaticItem->item_code, $automaticItem->name);
        $confirmedNode = $this->node($version, $confirmedItem->item_code, 'Иное официальное имя');
        $this->node($version, $rejectedItem->item_code, $rejectedItem->name);
        $user = User::factory()->create();
        $confirmed = $this->manualMapping(
            $confirmedItem,
            $version,
            ClassifierItemMappingReviewStatus::Confirmed,
            $confirmedNode,
            'automatic:legacy_candidate',
            $user->id,
        );
        $rejected = $this->manualMapping(
            $rejectedItem,
            $version,
            ClassifierItemMappingReviewStatus::Rejected,
            null,
            'automatic:legacy_candidate',
        );

        CarbonImmutable::setTestNow('2026-08-25 10:00:00');
        $first = app(GenerateClassifierItemMappings::class)->execute('okpd2');
        $automatic = StatisticalClassifierItemMapping::query()
            ->where('statistical_classifier_item_id', $automaticItem->id)
            ->where('classifier_version_id', $version->id)
            ->firstOrFail();
        $firstUpdatedAt = $automatic->updated_at;
        $firstConfirmedAt = $automatic->confirmed_at;

        CarbonImmutable::setTestNow('2026-08-25 11:00:00');
        $second = app(GenerateClassifierItemMappings::class)->execute('okpd2');

        $this->assertSame(3, StatisticalClassifierItemMapping::query()->where('classifier_version_id', $version->id)->count());
        $this->assertSame(2, $first->manualPreserved);
        $this->assertSame(2, $second->manualPreserved);
        $this->assertSame($firstUpdatedAt?->toISOString(), $automatic->refresh()->updated_at?->toISOString());
        $this->assertSame($firstConfirmedAt?->toISOString(), $automatic->confirmed_at?->toISOString());
        $this->assertSame($confirmed->public_id, $confirmed->refresh()->public_id);
        $this->assertSame(ClassifierItemMappingReviewStatus::Confirmed, $confirmed->review_status);
        $this->assertSame($rejected->public_id, $rejected->refresh()->public_id);
        $this->assertSame(ClassifierItemMappingReviewStatus::Rejected, $rejected->review_status);
        $this->assertSame($automaticNode->id, $automatic->classifier_node_id);
    }

    public function test_new_active_version_gets_new_mappings_without_reusing_previous_version_rows(): void
    {
        [$classifier, $versionA] = $this->activeClassifier();
        $item = $this->localItem('31.02.10.140', 'Наборы кухонной мебели');
        $nodeA = $this->node($versionA, $item->item_code, $item->name);
        app(GenerateClassifierItemMappings::class)->execute('okpd2');

        $versionB = StatisticalClassifierVersion::factory()->for($classifier, 'classifier')->create();
        $nodeB = $this->node($versionB, $item->item_code, $item->name);
        StatisticalClassifierActiveVersion::query()->where('classifier_id', $classifier->id)->update([
            'classifier_version_id' => $versionB->id,
            'activated_at' => now(),
            'updated_at' => now(),
        ]);
        app(GenerateClassifierItemMappings::class)->execute('okpd2');

        $this->assertDatabaseCount('statistical_classifier_item_mappings', 2);
        $this->assertDatabaseHas('statistical_classifier_item_mappings', [
            'classifier_version_id' => $versionA->id,
            'classifier_node_id' => $nodeA->id,
        ]);
        $this->assertDatabaseHas('statistical_classifier_item_mappings', [
            'classifier_version_id' => $versionB->id,
            'classifier_node_id' => $nodeB->id,
        ]);
    }

    public function test_mapping_command_requires_explicit_active_pointer_and_report_is_read_only_and_bounded(): void
    {
        $classifier = StatisticalClassifier::factory()->create(['code' => 'okpd2']);

        $this->artisan('price-indices:classifier:map', ['classifier' => 'okpd2'])
            ->expectsOutputToContain('[active_classifier_version_required]')
            ->assertFailed();

        $version = StatisticalClassifierVersion::factory()->for($classifier, 'classifier')->create();
        StatisticalClassifierActiveVersion::query()->create([
            'classifier_id' => $classifier->id,
            'classifier_version_id' => $version->id,
            'activated_at' => now(),
            'activation_reason' => 'test',
        ]);
        $item = $this->localItem('31.02.10.140', 'Локальное имя');
        $this->node($version, $item->item_code, 'Официальное имя');
        app(GenerateClassifierItemMappings::class)->execute('okpd2');
        $before = DB::table('statistical_classifier_item_mappings')->get()->map(fn ($row) => (array) $row)->all();
        $report = app(ReportClassifierItemMappings::class)->execute('okpd2', 1);

        $this->assertCount(1, $report->conflicts);
        $this->assertSame('exact_code_name_conflict', $report->conflicts[0]['reason']);

        $this->artisan('price-indices:classifier:map-report', ['classifier' => 'okpd2', '--limit' => 1])
            ->expectsOutputToContain('ambiguous')
            ->assertSuccessful();

        $this->assertSame($before, DB::table('statistical_classifier_item_mappings')->get()->map(fn ($row) => (array) $row)->all());
    }

    /** @return array{StatisticalClassifier, StatisticalClassifierVersion} */
    private function activeClassifier(): array
    {
        $classifier = StatisticalClassifier::factory()->create(['code' => 'okpd2']);
        $version = StatisticalClassifierVersion::factory()->for($classifier, 'classifier')->create();
        StatisticalClassifierActiveVersion::query()->create([
            'classifier_id' => $classifier->id,
            'classifier_version_id' => $version->id,
            'activated_at' => now(),
            'activation_reason' => 'test',
        ]);

        return [$classifier, $version];
    }

    private function localItem(string $code, string $name): StatisticalClassifierItem
    {
        $dataset = StatisticalDataset::factory()->create(['classifier_code' => 'okpd2_based']);

        return StatisticalClassifierItem::factory()->create([
            'dataset_id' => $dataset->id,
            'classifier_code' => 'okpd2_based',
            'item_code' => $code,
            'name' => $name,
            'normalized_name' => mb_strtolower(trim(str_replace("\u{00A0}", ' ', $name)), 'UTF-8'),
        ]);
    }

    private function node(StatisticalClassifierVersion $version, string $code, string $name): StatisticalClassifierNode
    {
        return StatisticalClassifierNode::factory()->create([
            'classifier_version_id' => $version->id,
            'code' => $code,
            'name' => $name,
            'normalized_name' => mb_strtolower($name, 'UTF-8'),
        ]);
    }

    private function manualMapping(
        StatisticalClassifierItem $item,
        StatisticalClassifierVersion $version,
        ClassifierItemMappingReviewStatus $status,
        ?StatisticalClassifierNode $node,
        string $method,
        ?int $confirmedBy = null,
    ): StatisticalClassifierItemMapping {
        return StatisticalClassifierItemMapping::query()->create([
            'statistical_classifier_item_id' => $item->id,
            'classifier_version_id' => $version->id,
            'classifier_node_id' => $node?->id,
            'mapping_type' => $node === null ? ClassifierItemMappingType::Unmapped : ClassifierItemMappingType::Ambiguous,
            'review_status' => $status,
            'method' => $method,
            'confirmed_at' => $status === ClassifierItemMappingReviewStatus::Confirmed ? now() : null,
            'confirmed_by' => $confirmedBy,
        ]);
    }

    private function assertMapping(
        StatisticalClassifierItem $item,
        StatisticalClassifierVersion $version,
        ClassifierItemMappingType $type,
        ClassifierItemMappingReviewStatus $status,
        ?int $nodeId,
    ): void {
        $mapping = StatisticalClassifierItemMapping::query()
            ->where('statistical_classifier_item_id', $item->id)
            ->where('classifier_version_id', $version->id)
            ->firstOrFail();

        $this->assertSame($type, $mapping->mapping_type);
        $this->assertSame($status, $mapping->review_status);
        $this->assertSame($nodeId, $mapping->classifier_node_id);
    }
}
