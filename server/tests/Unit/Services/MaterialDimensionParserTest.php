<?php

namespace Tests\Unit\Services;

use App\Models\MaterialDimensionParseFailure;
use App\Models\MaterialDimensionRule;
use App\Services\MaterialDimensionParser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MaterialDimensionParserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('material_dimension_parse_failures');
        Schema::dropIfExists('material_dimension_rules');

        Schema::create('material_dimension_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(100);
            $table->string('material_type', 32)->nullable();
            $table->string('source', 64)->nullable();
            $table->string('rule_type', 32)->default('regex');
            $table->json('config');
            $table->string('example_input', 1024)->nullable();
            $table->json('expected_result')->nullable();
            $table->decimal('confidence', 4, 2)->default(0.75);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('material_dimension_parse_failures', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->text('raw_text');
            $table->text('normalized_text');
            $table->string('material_type', 32)->nullable();
            $table->string('source', 64)->nullable();
            $table->string('parse_error_reason', 128)->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->integer('resolved_length_mm')->nullable();
            $table->integer('resolved_width_mm')->nullable();
            $table->decimal('resolved_thickness_mm', 8, 2)->nullable();
            $table->text('resolution_note')->nullable();
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->json('last_result')->nullable();
            $table->timestamps();
        });
    }

    public function test_parses_lxwxt_with_latin_separator(): void
    {
        $result = app(MaterialDimensionParser::class)->parse('2800x2070x16', 'plate', 'unit_test', ['log_failed' => false]);

        $this->assertTrue($result->success);
        $this->assertSame(2800.0, $result->lengthMm);
        $this->assertSame(2070.0, $result->widthMm);
        $this->assertSame(16.0, $result->thicknessMm);
    }

    public function test_parses_lxwxt_with_cyrillic_separator_and_mm(): void
    {
        $result = app(MaterialDimensionParser::class)->parse('2800х2070х16 мм', 'plate', 'unit_test', ['log_failed' => false]);

        $this->assertTrue($result->success);
        $this->assertSame(2800.0, $result->lengthMm);
        $this->assertSame(2070.0, $result->widthMm);
        $this->assertSame(16.0, $result->thicknessMm);
    }

    public function test_parses_lxwxt_with_star_separator(): void
    {
        $result = app(MaterialDimensionParser::class)->parse('2800*2070*16', 'plate', 'unit_test', ['log_failed' => false]);

        $this->assertTrue($result->success);
        $this->assertSame(2800.0, $result->lengthMm);
        $this->assertSame(2070.0, $result->widthMm);
        $this->assertSame(16.0, $result->thicknessMm);
    }

    public function test_parses_lxw_without_thickness(): void
    {
        $result = app(MaterialDimensionParser::class)->parse('2440x1220', 'plate', 'unit_test', ['log_failed' => false]);

        $this->assertTrue($result->success);
        $this->assertSame(2440.0, $result->lengthMm);
        $this->assertSame(1220.0, $result->widthMm);
        $this->assertNull($result->thicknessMm);
    }

    public function test_parses_lxw_plus_thickness_mm(): void
    {
        $result = app(MaterialDimensionParser::class)->parse('2750*1830 16 мм', 'plate', 'unit_test', ['log_failed' => false]);

        $this->assertTrue($result->success);
        $this->assertSame(2750.0, $result->lengthMm);
        $this->assertSame(1830.0, $result->widthMm);
        $this->assertSame(16.0, $result->thicknessMm);
    }

    public function test_parses_real_world_phrase_with_extra_tokens(): void
    {
        $result = app(MaterialDimensionParser::class)->parse(
            'Тэффи 594 КМ 2750*1830 16 мм СФ',
            'plate',
            'unit_test',
            ['log_failed' => false]
        );

        $this->assertTrue($result->success);
        $this->assertSame(2750.0, $result->lengthMm);
        $this->assertSame(1830.0, $result->widthMm);
        $this->assertSame(16.0, $result->thicknessMm);
    }

    public function test_manual_values_have_priority_over_auto_parse(): void
    {
        $parser = app(MaterialDimensionParser::class);
        $parsed = $parser->parse('2800x2070x16', 'plate', 'unit_test', ['log_failed' => false]);

        $resolved = $parser->mergeWithManual($parsed, 3000.0, 2000.0, 18.0, true);

        $this->assertTrue($resolved->success);
        $this->assertSame(3000.0, $resolved->lengthMm);
        $this->assertSame(2000.0, $resolved->widthMm);
        $this->assertSame(18.0, $resolved->thicknessMm);
        $this->assertSame('manual_override', $resolved->strategyName);
    }

    public function test_failed_parse_is_logged_and_deduplicated(): void
    {
        $parser = app(MaterialDimensionParser::class);

        $parser->parse('no dimensions here', 'plate', 'unit_test_failed');
        $parser->parse('no dimensions here', 'plate', 'unit_test_failed');

        $this->assertDatabaseCount('material_dimension_parse_failures', 1);

        /** @var MaterialDimensionParseFailure $failure */
        $failure = MaterialDimensionParseFailure::query()->firstOrFail();
        $this->assertSame(2, $failure->occurrences);
        $this->assertSame('no_matching_rule', $failure->parse_error_reason);
        $this->assertSame('unit_test_failed', $failure->source);
    }

    public function test_applies_managed_rule_from_database(): void
    {
        $rule = MaterialDimensionRule::create([
            'name' => 'by_format_rule',
            'is_active' => true,
            'priority' => 10,
            'material_type' => 'plate',
            'rule_type' => MaterialDimensionRule::RULE_TYPE_REGEX,
            'config' => [
                'pattern' => '\\b(\\d{4})\\s+by\\s+(\\d{4})\\s+t\\s*(\\d{2})\\b',
                'flags' => 'u',
                'use_normalized_text' => true,
                'captures' => [
                    'length_mm' => 1,
                    'width_mm' => 2,
                    'thickness_mm' => 3,
                ],
            ],
            'confidence' => 0.88,
        ]);

        $result = app(MaterialDimensionParser::class)->parse('Board 3000 by 1500 t 18', 'plate', 'unit_test', ['log_failed' => false]);

        $this->assertTrue($result->success);
        $this->assertSame('managed_rule', $result->source);
        $this->assertSame($rule->id, $result->ruleId);
        $this->assertSame(3000.0, $result->lengthMm);
        $this->assertSame(1500.0, $result->widthMm);
        $this->assertSame(18.0, $result->thicknessMm);
    }

    public function test_managed_rules_apply_by_priority(): void
    {
        $highPriority = MaterialDimensionRule::create([
            'name' => 'priority_top',
            'is_active' => true,
            'priority' => 5,
            'material_type' => 'plate',
            'rule_type' => MaterialDimensionRule::RULE_TYPE_REGEX,
            'config' => [
                'pattern' => 'priority\s+case',
                'flags' => 'u',
                'use_normalized_text' => true,
                'fixed' => [
                    'length_mm' => 2440,
                    'width_mm' => 1220,
                    'thickness_mm' => 10,
                ],
            ],
            'confidence' => 0.66,
        ]);

        MaterialDimensionRule::create([
            'name' => 'priority_low',
            'is_active' => true,
            'priority' => 50,
            'material_type' => 'plate',
            'rule_type' => MaterialDimensionRule::RULE_TYPE_REGEX,
            'config' => [
                'pattern' => 'priority\s+case',
                'flags' => 'u',
                'use_normalized_text' => true,
                'fixed' => [
                    'length_mm' => 2750,
                    'width_mm' => 1830,
                    'thickness_mm' => 16,
                ],
            ],
            'confidence' => 0.90,
        ]);

        $result = app(MaterialDimensionParser::class)->parse('priority-case', 'plate', 'unit_test', ['log_failed' => false]);

        $this->assertTrue($result->success);
        $this->assertSame($highPriority->id, $result->ruleId);
        $this->assertSame(2440.0, $result->lengthMm);
        $this->assertSame(1220.0, $result->widthMm);
        $this->assertSame(10.0, $result->thicknessMm);
    }

    public function test_normalizes_mixed_separators_and_spaces(): void
    {
        $result = app(MaterialDimensionParser::class)->parse('  МДФ   2800×2070 * 16   MM  ', 'plate', 'unit_test', ['log_failed' => false]);

        $this->assertTrue($result->success);
        $this->assertStringNotContainsString('×', $result->normalizedText);
        $this->assertStringNotContainsString('*', $result->normalizedText);
        $this->assertStringContainsString('mm', $result->normalizedText);
        $this->assertMatchesRegularExpression('/^[^\s].*[^\s]$/u', $result->normalizedText);
    }
}
