<?php

namespace Tests\Unit\Services;

use App\Models\ProjectPosition;
use App\Services\ProjectPositionBulkPolicyService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ProjectPositionBulkPolicyServiceTest extends TestCase
{
    private ProjectPositionBulkPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProjectPositionBulkPolicyService();
    }

    public function test_replace_edge_is_panel_only(): void
    {
        $positions = new Collection([
            (new ProjectPosition(['kind' => ProjectPosition::KIND_PANEL, 'edge_material_id' => 10]))->setAttribute('id', 1),
            (new ProjectPosition(['kind' => ProjectPosition::KIND_FACADE]))->setAttribute('id', 2),
        ]);

        $result = $this->service->splitApplicable($positions, 'replace_edge');

        $this->assertCount(1, $result['applicable']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame(2, $result['skipped'][0]['id']);
        $this->assertSame('requires_panel', $result['skipped'][0]['reason']);
    }

    public function test_set_edge_scheme_requires_edge_material(): void
    {
        $positions = new Collection([
            (new ProjectPosition(['kind' => ProjectPosition::KIND_PANEL, 'edge_material_id' => null]))->setAttribute('id', 1),
            (new ProjectPosition(['kind' => ProjectPosition::KIND_PANEL, 'edge_material_id' => 15]))->setAttribute('id', 2),
        ]);

        $result = $this->service->splitApplicable($positions, 'set_edge_scheme');

        $this->assertCount(1, $result['applicable']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame(1, $result['skipped'][0]['id']);
        $this->assertSame('missing_edge_material', $result['skipped'][0]['reason']);
    }

    public function test_clear_facade_material_is_facade_only(): void
    {
        $positions = new Collection([
            (new ProjectPosition(['kind' => ProjectPosition::KIND_PANEL]))->setAttribute('id', 1),
            (new ProjectPosition(['kind' => ProjectPosition::KIND_FACADE]))->setAttribute('id', 2),
        ]);

        $result = $this->service->splitApplicable($positions, 'clear_facade_material_id');

        $this->assertCount(1, $result['applicable']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame(1, $result['skipped'][0]['id']);
        $this->assertSame('requires_facade', $result['skipped'][0]['reason']);
    }

    public function test_resolve_operation_for_clear_field(): void
    {
        $operation = $this->service->resolveOperation('update', [], 'edge_scheme');
        $this->assertSame('clear_edge_scheme', $operation);
    }
}
