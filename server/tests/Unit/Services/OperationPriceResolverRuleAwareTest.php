<?php

namespace Tests\Unit\Services;

use App\Models\Operation;
use App\Models\OperationPrice;
use App\Models\PriceList;
use App\Models\PriceListVersion;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PriceImport\OperationPriceResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Block 3: Verify thickness-aware deterministic rule selection in
 * OperationPriceResolver::getPricesForVersionBatchWithRuleContext().
 *
 * Precedence under test (highest → lowest):
 *   1. Bounded  match (min <= t <= max), narrowest interval first
 *   2. Lower-only match (min <= t, no max)
 *   3. Upper-only match (no min, max >= t)
 *   4. Unbounded default row (both null)
 *   5. Scalar last-resort (any row)
 *
 * Null thickness skips steps 1–3 and lands on step 4 or 5.
 */
class OperationPriceResolverRuleAwareTest extends TestCase
{
    use DatabaseTransactions;

    private OperationPriceResolver $resolver;
    private int $versionId;
    private int $supplierId;
    private int $opId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new OperationPriceResolver();

        $user     = User::factory()->create();
        $supplier = Supplier::create([
            'user_id' => $user->id,
            'name'    => 'Test Supplier ' . uniqid(),
        ]);
        $priceList = PriceList::create([
            'supplier_id' => $supplier->id,
            'name'        => 'Test List ' . uniqid(),
            'type'        => 'operations',
            'is_active'   => true,
        ]);
        $version = PriceListVersion::create([
            'price_list_id'  => $priceList->id,
            'version_number' => 1,
            'source_type'    => 'file',
            'storage_disk'   => 'local',
            'currency'       => 'RUB',
            'status'         => PriceListVersion::STATUS_ACTIVE,
        ]);

        $op = Operation::create([
            'name'     => 'Test Op ' . uniqid(),
            'category' => 'cutting',
            'unit'     => 'm2',
        ]);

        $this->versionId  = $version->id;
        $this->supplierId = $supplier->id;
        $this->opId       = $op->id;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Insert an OperationPrice row for $this->opId in $this->versionId.
     */
    private function makePrice(
        float $price,
        ?float $min,
        ?float $max,
        string $type = OperationPrice::PRICE_TYPE_RETAIL
    ): OperationPrice {
        return OperationPrice::create([
            'operation_id'            => $this->opId,
            'price_list_version_id'   => $this->versionId,
            'supplier_id'             => $this->supplierId,
            'price_type'              => $type,
            'source_price'            => $price,
            'conversion_factor'       => 1.0,
            'price_per_internal_unit' => $price,
            'source_unit'             => 'm2',
            'source_name'             => 'Test',
            'match_confidence'        => 1.0,
            'min_thickness'           => $min,
            'max_thickness'           => $max,
        ]);
    }

    /**
     * Call the method under test with a single operation and a thickness hint.
     */
    private function resolve(?float $thickness): array
    {
        $results = $this->resolver->getPricesForVersionBatchWithRuleContext(
            $this->versionId,
            [$this->opId => ['thickness' => $thickness, 'exclusion_group' => null]]
        );

        return $results[$this->opId] ?? [];
    }

    // -----------------------------------------------------------------------
    // Base: empty inputs / version guard
    // -----------------------------------------------------------------------

    public function test_empty_rule_contexts_returns_empty_array(): void
    {
        $results = $this->resolver->getPricesForVersionBatchWithRuleContext(
            $this->versionId,
            []
        );

        $this->assertSame([], $results);
    }

    public function test_inactive_version_returns_version_not_active_for_all(): void
    {
        PriceListVersion::where('id', $this->versionId)
            ->update(['status' => PriceListVersion::STATUS_INACTIVE]);

        $result = $this->resolve(16.0);

        $this->assertSame('not_found', $result['source']);
        $this->assertSame('version_not_active', $result['reason']);
        $this->assertSame(0.0, $result['price']);
    }

    public function test_no_rows_for_operation_returns_not_found(): void
    {
        // No OperationPrice rows inserted
        $result = $this->resolve(16.0);

        $this->assertSame('not_found', $result['source']);
        $this->assertSame('not_found_for_version', $result['reason']);
        $this->assertSame(0.0, $result['price']);
    }

    // -----------------------------------------------------------------------
    // Step 4: unbounded row (single generic row)
    // -----------------------------------------------------------------------

    public function test_unbounded_generic_row_is_returned_when_only_row(): void
    {
        $this->makePrice(100.0, null, null);

        $result = $this->resolve(16.0);

        $this->assertSame(100.0, $result['price']);
        $this->assertSame('project_version', $result['source']); // both bounds null → not a rule match
        $this->assertNull($result['matched_min_thickness']);
        $this->assertNull($result['matched_max_thickness']);
    }

    // -----------------------------------------------------------------------
    // Step 1: bounded match — narrowest interval wins
    // -----------------------------------------------------------------------

    public function test_bounded_match_is_selected_over_unbounded(): void
    {
        $this->makePrice(100.0, null, null);   // unbounded — step 4
        $this->makePrice(150.0, 10.0, 20.0);  // bounded,  covers 16 — step 1

        $result = $this->resolve(16.0);

        $this->assertSame(150.0, $result['price']);
        $this->assertSame('project_version_rule', $result['source']);
        $this->assertEquals(10.0, $result['matched_min_thickness']);
        $this->assertEquals(20.0, $result['matched_max_thickness']);
    }

    public function test_narrowest_bounded_interval_wins(): void
    {
        $this->makePrice(100.0, 10.0, 30.0);  // wider — covers 16
        $this->makePrice(200.0, 14.0, 18.0);  // narrower — covers 16 → should win

        $result = $this->resolve(16.0);

        $this->assertSame(200.0, $result['price']);
        $this->assertEquals(14.0, $result['matched_min_thickness']);
        $this->assertEquals(18.0, $result['matched_max_thickness']);
    }

    public function test_bounded_row_not_matching_is_not_selected(): void
    {
        $this->makePrice(200.0, 20.0, 30.0);  // outside range for thickness=16
        $this->makePrice(100.0, null, null);  // unbounded fallback

        $result = $this->resolve(16.0);

        // Bounded row is out of range → falls to step 4 (unbounded)
        $this->assertSame(100.0, $result['price']);
    }

    // -----------------------------------------------------------------------
    // Step 2: lower-only match
    // -----------------------------------------------------------------------

    public function test_lower_only_match_wins_over_unbounded(): void
    {
        $this->makePrice(100.0, null, null);  // unbounded — step 4
        $this->makePrice(160.0, 10.0, null); // lower-only — covers 16 — step 2

        $result = $this->resolve(16.0);

        $this->assertSame(160.0, $result['price']);
        $this->assertSame('project_version_rule', $result['source']);
    }

    public function test_lower_only_most_specific_wins(): void
    {
        $this->makePrice(100.0, 5.0, null);   // lower bound 5
        $this->makePrice(120.0, 12.0, null);  // higher lower bound — more specific

        $result = $this->resolve(16.0);

        $this->assertSame(120.0, $result['price']);
        $this->assertEquals(12.0, $result['matched_min_thickness']);
    }

    // -----------------------------------------------------------------------
    // Step 3: upper-only match
    // -----------------------------------------------------------------------

    public function test_upper_only_match_wins_over_unbounded(): void
    {
        $this->makePrice(100.0, null, null);   // unbounded — step 4
        $this->makePrice(140.0, null, 20.0);   // upper-only — covers 16 — step 3

        $result = $this->resolve(16.0);

        $this->assertSame(140.0, $result['price']);
        $this->assertSame('project_version_rule', $result['source']);
    }

    public function test_upper_only_narrowest_upper_bound_wins(): void
    {
        $this->makePrice(100.0, null, 30.0);  // wider upper bound
        $this->makePrice(120.0, null, 18.0);  // narrower upper bound — more specific

        $result = $this->resolve(16.0);

        $this->assertSame(120.0, $result['price']);
        $this->assertEquals(18.0, $result['matched_max_thickness']);
    }

    // -----------------------------------------------------------------------
    // Precedence ordering: step 1 > step 2 > step 3 > step 4
    // -----------------------------------------------------------------------

    public function test_bounded_beats_lower_only(): void
    {
        $this->makePrice(200.0, 10.0, 20.0);  // bounded — step 1
        $this->makePrice(150.0, 10.0, null);  // lower-only — step 2

        $result = $this->resolve(16.0);

        $this->assertSame(200.0, $result['price']);
    }

    public function test_lower_only_beats_upper_only(): void
    {
        $this->makePrice(150.0, 10.0, null);  // lower-only — step 2
        $this->makePrice(130.0, null, 20.0);  // upper-only — step 3

        $result = $this->resolve(16.0);

        $this->assertSame(150.0, $result['price']);
    }

    public function test_upper_only_beats_unbounded(): void
    {
        $this->makePrice(100.0, null, null);  // unbounded — step 4
        $this->makePrice(130.0, null, 20.0);  // upper-only — step 3

        $result = $this->resolve(16.0);

        $this->assertSame(130.0, $result['price']);
    }

    // -----------------------------------------------------------------------
    // Null thickness: skip steps 1–3, land on step 4
    // -----------------------------------------------------------------------

    public function test_null_thickness_skips_to_unbounded_step4(): void
    {
        $this->makePrice(200.0, 10.0, 20.0);  // bounded — would be step 1 with thickness
        $this->makePrice(160.0, 10.0, null);  // lower — would be step 2 with thickness
        $this->makePrice(100.0, null, null);  // unbounded — step 4

        $result = $this->resolve(null);

        // Steps 1–3 skipped; unbounded row returned
        $this->assertSame(100.0, $result['price']);
        $this->assertSame('project_version', $result['source']); // unbounded not a rule row
    }

    public function test_null_thickness_falls_back_to_any_row_when_no_unbounded(): void
    {
        // Only a bounded row exists; no unbounded row
        $this->makePrice(200.0, 10.0, 20.0);

        $result = $this->resolve(null);

        // Step 4 fails (no unbounded row) → step 5 (any row)
        $this->assertSame(200.0, $result['price']);
    }

    // -----------------------------------------------------------------------
    // Response shape contract
    // -----------------------------------------------------------------------

    public function test_response_contains_expected_keys(): void
    {
        $this->makePrice(150.0, 10.0, 20.0);

        $result = $this->resolve(16.0);

        $this->assertArrayHasKey('price', $result);
        $this->assertArrayHasKey('source', $result);
        $this->assertArrayHasKey('version_id', $result);
        $this->assertArrayHasKey('supplier_id', $result);
        $this->assertArrayHasKey('unit', $result);
        $this->assertArrayHasKey('match_confidence', $result);
        $this->assertArrayHasKey('matched_min_thickness', $result);
        $this->assertArrayHasKey('matched_max_thickness', $result);
        $this->assertSame($this->versionId, $result['version_id']);
    }

    // -----------------------------------------------------------------------
    // Block 4: getPricesForVersionBatchItems — multi-item per operation_id
    // -----------------------------------------------------------------------

    /**
     * Helper: call getPricesForVersionBatchItems with an explicit items list.
     */
    private function resolveItems(array $items): array
    {
        return $this->resolver->getPricesForVersionBatchItems($this->versionId, $items);
    }

    public function test_batch_items_empty_returns_empty(): void
    {
        $result = $this->resolveItems([]);

        $this->assertSame([], $result);
    }

    public function test_batch_items_result_keyed_by_caller_key(): void
    {
        $this->makePrice(100.0, null, null);

        $result = $this->resolveItems([
            ['key' => 'my-key-1', 'operation_id' => $this->opId, 'thickness' => null],
        ]);

        $this->assertArrayHasKey('my-key-1', $result);
        $this->assertSame(100.0, $result['my-key-1']['price']);
    }

    public function test_batch_items_multiple_items_same_operation_different_thickness(): void
    {
        // Bounded row for 10–20 mm at price 200; unbounded at 100
        $this->makePrice(200.0, 10.0, 20.0);
        $this->makePrice(100.0, null, null);

        $items = [
            ['key' => 'bucket_16', 'operation_id' => $this->opId, 'thickness' => 16.0],
            ['key' => 'bucket_25', 'operation_id' => $this->opId, 'thickness' => 25.0],
        ];
        $result = $this->resolveItems($items);

        // thickness=16 → bounded rule row (200)
        $this->assertSame(200.0, $result['bucket_16']['price']);
        $this->assertSame('project_version_rule', $result['bucket_16']['source']);

        // thickness=25 → outside 10–20, falls to unbounded row (100)
        $this->assertSame(100.0, $result['bucket_25']['price']);
        $this->assertSame('project_version', $result['bucket_25']['source']);
    }

    public function test_batch_items_both_in_same_rule_row(): void
    {
        // Single bounded row covering both thicknesses
        $this->makePrice(200.0, 10.0, 30.0);

        $items = [
            ['key' => 'bucket_12', 'operation_id' => $this->opId, 'thickness' => 12.0],
            ['key' => 'bucket_18', 'operation_id' => $this->opId, 'thickness' => 18.0],
        ];
        $result = $this->resolveItems($items);

        $this->assertSame(200.0, $result['bucket_12']['price']);
        $this->assertSame(200.0, $result['bucket_18']['price']);
        $this->assertSame('project_version_rule', $result['bucket_12']['source']);
        $this->assertSame('project_version_rule', $result['bucket_18']['source']);
    }

    public function test_batch_items_key_passthrough_for_no_price_operations(): void
    {
        // No rows inserted — should return not_found keyed by the caller key
        $result = $this->resolveItems([
            ['key' => 'missing-op', 'operation_id' => $this->opId, 'thickness' => 16.0],
        ]);

        $this->assertArrayHasKey('missing-op', $result);
        $this->assertSame('not_found', $result['missing-op']['source']);
        $this->assertSame(0.0, $result['missing-op']['price']);
    }

    public function test_batch_items_inactive_version_returns_version_not_active(): void
    {
        PriceListVersion::where('id', $this->versionId)
            ->update(['status' => PriceListVersion::STATUS_INACTIVE]);

        $result = $this->resolveItems([
            ['key' => 'k1', 'operation_id' => $this->opId, 'thickness' => 16.0],
        ]);

        $this->assertSame('not_found', $result['k1']['source']);
        $this->assertSame('version_not_active', $result['k1']['reason']);
    }

    public function test_batch_items_uses_one_db_query_for_multiple_items(): void
    {
        // Two items for the same operation — result should be consistent with two
        // independent calls (verifies there is no cross-bucket contamination from
        // shared DB row sets).
        $this->makePrice(200.0, 10.0, 20.0);
        $this->makePrice(100.0, null, null);

        $result = $this->resolveItems([
            ['key' => 'a', 'operation_id' => $this->opId, 'thickness' => 16.0],
            ['key' => 'b', 'operation_id' => $this->opId, 'thickness' => 16.0],
        ]);

        // Both items should get the same bounded row (no cross-contamination)
        $this->assertSame(200.0, $result['a']['price']);
        $this->assertSame(200.0, $result['b']['price']);
    }

    // -----------------------------------------------------------------------
    // Block 7: exclusion_group pre-filter in selectRuleAwareRow
    // -----------------------------------------------------------------------

    /**
     * Insert an OperationPrice row with a specific exclusion_group.
     * Used only for Block 7 exclusion_group tests; existing makePrice() is untouched.
     */
    private function makePriceWithGroup(
        float $price,
        ?float $min,
        ?float $max,
        ?string $exclusionGroup
    ): OperationPrice {
        return OperationPrice::create([
            'operation_id'            => $this->opId,
            'price_list_version_id'   => $this->versionId,
            'supplier_id'             => $this->supplierId,
            'price_type'              => OperationPrice::PRICE_TYPE_RETAIL,
            'source_price'            => $price,
            'conversion_factor'       => 1.0,
            'price_per_internal_unit' => $price,
            'source_unit'             => 'm2',
            'source_name'             => 'Test',
            'match_confidence'        => 1.0,
            'min_thickness'           => $min,
            'max_thickness'           => $max,
            'exclusion_group'         => $exclusionGroup,
        ]);
    }

    /**
     * Same operation_id, two rows with different exclusion_group values.
     * Asking for 'cutting' must return only the 'cutting' row.
     */
    public function test_exclusion_group_filters_to_matching_rows_in_withRuleContext(): void
    {
        $this->makePriceWithGroup(300.0, null, null, 'edging');   // wrong group
        $this->makePriceWithGroup(150.0, null, null, 'cutting');  // correct group

        $results = $this->resolver->getPricesForVersionBatchWithRuleContext(
            $this->versionId,
            [$this->opId => ['thickness' => null, 'exclusion_group' => 'cutting']]
        );
        $result = $results[$this->opId] ?? [];

        $this->assertSame(150.0, $result['price'],
            'Resolver must return the row tagged with the requested exclusion_group');
    }

    /**
     * When the requested exclusion_group ('edging') matches no rows in the version,
     * the resolver must fall back to the full row set and still return a price.
     * (Backward-compatible behavior: untagged price catalogs are not broken.)
     */
    public function test_exclusion_group_falls_back_to_all_rows_when_no_group_match(): void
    {
        // Both rows have a different group — no 'edging' rows exist.
        $this->makePriceWithGroup(100.0, null, null, 'cutting');
        $this->makePriceWithGroup(120.0, null, null, 'cutting');

        $results = $this->resolver->getPricesForVersionBatchWithRuleContext(
            $this->versionId,
            [$this->opId => ['thickness' => null, 'exclusion_group' => 'edging']]
        );
        $result = $results[$this->opId] ?? [];

        // Falls back to all rows; unbounded step 4 wins (first row by insertion order)
        $this->assertSame('project_version', $result['source']);
        $this->assertGreaterThan(0.0, $result['price'], 'Fallback must still return a price');
    }

    /**
     * Null exclusion_group in rule context must not filter any rows —
     * existing 5-step precedence runs on all rows unchanged.
     */
    public function test_null_exclusion_group_uses_all_rows(): void
    {
        $this->makePriceWithGroup(300.0, null, null, 'edging');
        $this->makePriceWithGroup(150.0, 10.0, 20.0, 'cutting');  // bounded — step 1

        // null exclusion_group → no pre-filter → bounded row wins at step 1
        $result = $this->resolve(16.0);  // uses exclusion_group=null via $this->resolve()

        $this->assertSame(150.0, $result['price'],
            'Null exclusion_group must not filter rows; bounded row must win at step 1');
        $this->assertSame('project_version_rule', $result['source']);
    }

    /**
     * exclusion_group filter + thickness precedence: within the filtered group,
     * the bounded row (step 1) must still beat the unbounded row (step 4).
     */
    public function test_exclusion_group_with_thickness_still_applies_5step_precedence(): void
    {
        $this->makePriceWithGroup(500.0, null, null, 'edging');   // wrong group, unbounded
        $this->makePriceWithGroup(100.0, null, null, 'cutting');  // correct group, unbounded — step 4
        $this->makePriceWithGroup(200.0, 10.0, 20.0, 'cutting'); // correct group, bounded 10–20 — step 1

        $results = $this->resolver->getPricesForVersionBatchWithRuleContext(
            $this->versionId,
            [$this->opId => ['thickness' => 16.0, 'exclusion_group' => 'cutting']]
        );
        $result = $results[$this->opId] ?? [];

        // Within 'cutting' group, bounded row (step 1) must beat unbounded (step 4)
        $this->assertSame(200.0, $result['price'],
            'Bounded row must win over unbounded within the filtered exclusion group');
        $this->assertSame('project_version_rule', $result['source']);
    }

    /**
     * getPricesForVersionBatchItems must also pass exclusion_group through to the
     * row selection, returning the group-matched row for each bucket item.
     */
    public function test_batch_items_exclusion_group_selects_correct_group_row(): void
    {
        $this->makePriceWithGroup(300.0, null, null, 'edging');   // wrong group
        $this->makePriceWithGroup(175.0, null, null, 'cutting');  // correct group

        $result = $this->resolveItems([
            [
                'key'             => 'bucket_cutting',
                'operation_id'    => $this->opId,
                'thickness'       => null,
                'exclusion_group' => 'cutting',
            ],
        ]);

        $this->assertSame(175.0, $result['bucket_cutting']['price'],
            'getPricesForVersionBatchItems must respect exclusion_group in item');
    }
}
