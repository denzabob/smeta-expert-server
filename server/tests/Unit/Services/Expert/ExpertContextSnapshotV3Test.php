<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Expert;

use App\Services\Expert\ExpertAttachmentBatch;
use App\Services\Expert\ExpertContextPack;
use App\Services\Expert\ExpertContextResolution;
use Tests\TestCase;

final class ExpertContextSnapshotV3Test extends TestCase
{
    public function test_v3_snapshot_keeps_resolution_and_batch_immutable(): void
    {
        $pack = new ExpertContextPack(
            'Background',
            ['a', 'b'],
            ['c'],
            [],
            ['a', 'b'],
            'targeted_multi',
            'focused',
            false,
            currentAttachmentBatch: new ExpertAttachmentBatch(null, ['b', 'a']),
            resolution: new ExpertContextResolution(
                [['material_id' => 'a', 'role' => 'primary', 'origin' => 'current', 'reason_code' => 'current_batch_reference']],
                ['c'],
                'single',
                'focused',
                false,
                'structural',
                1.0,
            ),
        );

        $snapshot = $pack->snapshot('message-1');
        $this->assertSame(3, $snapshot['version']);
        $this->assertSame(['message_id' => 'message-1', 'ordered_material_ids' => ['b', 'a']], $snapshot['current_attachment_batch']);
        $this->assertSame(['c'], $snapshot['hard_excluded_ids']);
        $this->assertSame('structural', $snapshot['resolver']['source']);
        $this->assertArrayHasKey('hash', $snapshot['candidate_set']);

        $restored = ExpertContextPack::fromSnapshot('Changed background', $snapshot);
        $this->assertSame(['a'], $restored->resolvedMaterials);
        $this->assertSame('Background', $restored->projectCore);
        $this->assertSame($snapshot['selected_sources'], $restored->resolution?->selected);
    }

    public function test_v2_snapshot_retains_its_original_material_ids(): void
    {
        $restored = ExpertContextPack::fromSnapshot('Current background', [
            'version' => 2,
            'project_core' => 'Old background',
            'current_material_ids' => ['a'],
            'active_material_ids' => ['b'],
            'historical_material_ids' => [],
            'resolved_material_ids' => ['a', 'b'],
            'scope' => 'targeted_multi',
            'coverage_mode' => 'focused',
        ]);

        $this->assertSame(['a', 'b'], $restored->resolvedMaterials);
        $this->assertSame('Old background', $restored->projectCore);
        $this->assertNull($restored->resolution);
    }

    public function test_v3_round_trip_preserves_primary_and_comparison_roles(): void
    {
        $resolution = new ExpertContextResolution(
            [
                ['material_id' => 'current', 'role' => 'primary', 'origin' => 'current', 'reason_code' => 'current_batch_reference'],
                ['material_id' => 'prior', 'role' => 'comparison', 'origin' => 'recent', 'reason_code' => 'recent_source_set'],
            ],
            [],
            'targeted_multi',
            'focused',
            false,
            'structural',
            1.0,
        );
        $pack = new ExpertContextPack(
            '', ['current'], [], ['prior'], ['current', 'prior'], 'targeted_multi', 'focused', false,
            resolution: $resolution,
        );

        $restored = ExpertContextPack::fromSnapshot('', $pack->snapshot('message'));

        $this->assertSame($resolution->selected, $restored->resolution?->selected);
        $this->assertSame(['current', 'prior'], $restored->resolvedMaterials);
        $this->assertSame(['current'], $restored->currentMaterials);
        $this->assertSame(['prior'], $restored->historicalMaterials);
    }
}
