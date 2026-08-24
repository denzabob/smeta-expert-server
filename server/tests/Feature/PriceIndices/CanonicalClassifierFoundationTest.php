<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifier;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierActiveVersion;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierNode;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierVersion;
use App\Domain\PriceIndices\Domain\Enums\ClassifierSemanticLevel;
use App\Domain\PriceIndices\Domain\Enums\ClassifierVersionStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CanonicalClassifierFoundationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_classifier_code_is_unique(): void
    {
        $classifier = StatisticalClassifier::factory()->create(['code' => 'okpd2']);

        $this->expectException(QueryException::class);

        StatisticalClassifier::factory()->create(['code' => $classifier->code]);
    }

    public function test_different_classifiers_are_allowed(): void
    {
        $first = StatisticalClassifier::factory()->create();
        $second = StatisticalClassifier::factory()->create();

        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->code, $second->code);
    }

    public function test_canonical_entities_follow_the_uuid_public_id_convention(): void
    {
        $classifier = StatisticalClassifier::factory()->create();
        $version = StatisticalClassifierVersion::factory()->for($classifier, 'classifier')->create();
        $node = StatisticalClassifierNode::factory()->for($version, 'version')->create();

        foreach ([$classifier, $version, $node] as $model) {
            $this->assertTrue(Str::isUuid($model->public_id));
            $this->assertSame('public_id', $model->getRouteKeyName());
        }
    }

    public function test_version_identity_is_unique_within_classifier_only(): void
    {
        $firstClassifier = StatisticalClassifier::factory()->create();
        $secondClassifier = StatisticalClassifier::factory()->create();
        $label = '145/2026';

        StatisticalClassifierVersion::factory()->for($firstClassifier, 'classifier')->create([
            'version_label' => $label,
        ]);
        StatisticalClassifierVersion::factory()->for($secondClassifier, 'classifier')->create([
            'version_label' => $label,
        ]);

        $this->expectException(QueryException::class);

        StatisticalClassifierVersion::factory()->for($firstClassifier, 'classifier')->create([
            'version_label' => $label,
        ]);
    }

    public function test_classifier_supports_multiple_distinct_versions_and_typed_statuses(): void
    {
        $classifier = StatisticalClassifier::factory()->create();

        foreach (ClassifierVersionStatus::cases() as $index => $status) {
            $version = StatisticalClassifierVersion::factory()
                ->for($classifier, 'classifier')
                ->create([
                    'version_label' => 'version-'.$index,
                    'status' => $status,
                ]);

            $this->assertSame($status, $version->status);
        }

        $this->assertCount(3, $classifier->versions()->get());
    }

    public function test_effective_dates_remain_dates_and_future_scheduled_version_is_allowed(): void
    {
        $version = StatisticalClassifierVersion::factory()->create([
            'version_label' => '146/2026',
            'effective_from' => '2027-01-01',
            'effective_to' => '2027-12-31',
            'approved_at' => '2026-08-20',
            'source_published_at' => '2026-08-20 14:30:00',
            'status' => ClassifierVersionStatus::Scheduled,
        ]);

        $this->assertSame('2027-01-01', $version->effective_from->toDateString());
        $this->assertSame('2027-12-31', $version->effective_to->toDateString());
        $this->assertSame('2026-08-20', $version->approved_at->toDateString());
        $this->assertSame('2026-08-20 14:30:00', $version->source_published_at->format('Y-m-d H:i:s'));
        $this->assertSame(ClassifierVersionStatus::Scheduled, $version->status);
    }

    public function test_node_code_is_unique_within_version_only(): void
    {
        $classifier = StatisticalClassifier::factory()->create();
        $firstVersion = StatisticalClassifierVersion::factory()->for($classifier, 'classifier')->create();
        $secondVersion = StatisticalClassifierVersion::factory()->for($classifier, 'classifier')->create();
        $code = '01.11.11.111';

        StatisticalClassifierNode::factory()->for($firstVersion, 'version')->create(['code' => $code]);
        StatisticalClassifierNode::factory()->for($secondVersion, 'version')->create(['code' => $code]);

        $this->expectException(QueryException::class);

        StatisticalClassifierNode::factory()->for($firstVersion, 'version')->create(['code' => $code]);
    }

    public function test_nullable_parent_and_same_version_parent_are_allowed(): void
    {
        $version = StatisticalClassifierVersion::factory()->create();
        $root = StatisticalClassifierNode::factory()->for($version, 'version')->create([
            'code' => 'A',
            'semantic_level' => ClassifierSemanticLevel::Section,
            'parent_node_id' => null,
        ]);
        $child = StatisticalClassifierNode::factory()->for($version, 'version')->create([
            'code' => '01',
            'semantic_level' => ClassifierSemanticLevel::ClassLevel,
            'formal_depth' => 2,
            'parent_node_id' => $root->id,
        ]);

        $this->assertNull($root->parent);
        $this->assertTrue($child->parent->is($root));
        $this->assertTrue($root->children->contains($child));
    }

    public function test_database_rejects_parent_from_another_version(): void
    {
        $classifier = StatisticalClassifier::factory()->create();
        $parentVersion = StatisticalClassifierVersion::factory()->for($classifier, 'classifier')->create();
        $childVersion = StatisticalClassifierVersion::factory()->for($classifier, 'classifier')->create();
        $parent = StatisticalClassifierNode::factory()->for($parentVersion, 'version')->create();

        $this->expectException(QueryException::class);

        DB::table('statistical_classifier_nodes')->insert([
            'public_id' => (string) Str::uuid(),
            'classifier_version_id' => $childVersion->id,
            'code' => 'cross-version-child',
            'name' => 'Cross-version child',
            'normalized_name' => 'cross-version child',
            'semantic_level' => ClassifierSemanticLevel::ClassLevel->value,
            'formal_depth' => 2,
            'parent_node_id' => $parent->id,
            'source_order' => null,
            'notes_text' => null,
            'metadata_json' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_all_required_semantic_levels_are_persisted_as_typed_values(): void
    {
        $version = StatisticalClassifierVersion::factory()->create();

        foreach (ClassifierSemanticLevel::cases() as $index => $level) {
            $node = StatisticalClassifierNode::factory()->for($version, 'version')->create([
                'code' => 'level-'.$index,
                'semantic_level' => $level,
            ]);

            $this->assertSame($level, $node->semantic_level);
        }
    }

    public function test_only_one_active_pointer_is_allowed_per_classifier(): void
    {
        $classifier = StatisticalClassifier::factory()->create();
        $firstVersion = StatisticalClassifierVersion::factory()->for($classifier, 'classifier')->create();
        $secondVersion = StatisticalClassifierVersion::factory()->for($classifier, 'classifier')->create();

        StatisticalClassifierActiveVersion::query()->create([
            'classifier_id' => $classifier->id,
            'classifier_version_id' => $firstVersion->id,
            'activated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('statistical_classifier_active_versions')->insert([
            'classifier_id' => $classifier->id,
            'classifier_version_id' => $secondVersion->id,
            'activated_at' => now(),
            'activated_by' => null,
            'activation_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_database_rejects_active_version_from_another_classifier(): void
    {
        $firstClassifier = StatisticalClassifier::factory()->create();
        $secondClassifier = StatisticalClassifier::factory()->create();
        $secondVersion = StatisticalClassifierVersion::factory()
            ->for($secondClassifier, 'classifier')
            ->create();

        $this->expectException(QueryException::class);

        DB::table('statistical_classifier_active_versions')->insert([
            'classifier_id' => $firstClassifier->id,
            'classifier_version_id' => $secondVersion->id,
            'activated_at' => now(),
            'activated_by' => null,
            'activation_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_classifier_may_have_no_active_pointer(): void
    {
        $classifier = StatisticalClassifier::factory()->create();

        $this->assertNull($classifier->activeVersionPointer);
    }
}
