<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const EXTENDED_VALUES = ['none', 'O', '=', '||', 'L', 'П', 'long_one', 'short_one'];
    private const LEGACY_VALUES = ['none', 'O', '=', '||', 'L', 'П'];

    public function up(): void
    {
        $this->modifyEnumIfNeeded('project_positions', 'edge_scheme', self::EXTENDED_VALUES);
        $this->modifyEnumIfNeeded('detail_types', 'edge_processing', self::EXTENDED_VALUES);
    }

    public function down(): void
    {
        $this->normalizeExtendedValues('project_positions', 'edge_scheme');
        $this->normalizeExtendedValues('detail_types', 'edge_processing');

        $this->modifyEnumIfNeeded('project_positions', 'edge_scheme', self::LEGACY_VALUES);
        $this->modifyEnumIfNeeded('detail_types', 'edge_processing', self::LEGACY_VALUES);
    }

    private function modifyEnumIfNeeded(string $table, string $column, array $values): void
    {
        if (!Schema::hasColumn($table, $column)) {
            return;
        }

        $columnQuoted = DB::getPdo()->quote($column);
        $columnInfo = DB::selectOne("SHOW COLUMNS FROM `{$table}` LIKE {$columnQuoted}");

        if (!$columnInfo) {
            return;
        }

        $type = (string) ($columnInfo->Type ?? '');

        if (!str_starts_with(strtolower($type), 'enum(')) {
            return;
        }

        if ($this->enumContainsAll($type, $values)) {
            return;
        }

        $nullable = (($columnInfo->Null ?? '') === 'YES') ? 'NULL' : 'NOT NULL';

        $default = $columnInfo->Default ?? null;
        $defaultSql = $default === null
            ? ''
            : ' DEFAULT ' . DB::getPdo()->quote((string) $default);

        $enumSql = implode(',', array_map(
            static fn (string $value) => DB::getPdo()->quote($value),
            $values,
        ));

        DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` ENUM({$enumSql}) {$nullable}{$defaultSql}");
    }

    private function normalizeExtendedValues(string $table, string $column): void
    {
        if (!Schema::hasColumn($table, $column)) {
            return;
        }

        DB::table($table)
            ->whereIn($column, ['long_one', 'short_one'])
            ->update([$column => 'none']);
    }

    private function enumContainsAll(string $type, array $values): bool
    {
        foreach ($values as $value) {
            if (!str_contains($type, "'" . str_replace("'", "\\'", $value) . "'")) {
                return false;
            }
        }

        return true;
    }
};