<?php

namespace Tests\Feature\Expert;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExpertSchemaBaselineTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_identity_columns_exist_after_default_fresh_migration_path(): void
    {
        $tables = [
            'projects',
            'expert_projects',
            'expert_research_objects',
            'expert_conversations',
            'expert_messages',
            'expert_project_materials',
            'expert_findings',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(
                Schema::hasColumn($table, 'public_id'),
                "Expected {$table}.public_id to exist after the default migration path."
            );

            $column = collect(Schema::getColumns($table))
                ->firstWhere('name', 'public_id');

            $this->assertNotNull($column);
            $this->assertFalse(
                $column['nullable'],
                "Expected {$table}.public_id to be NOT NULL."
            );

            $hasUniqueIndex = collect(Schema::getIndexes($table))->contains(
                fn (array $index): bool => $index['unique']
                    && $index['columns'] === ['public_id']
            );

            $this->assertTrue(
                $hasUniqueIndex,
                "Expected {$table}.public_id to have a unique index."
            );
        }
    }
}
