<?php

namespace App\Console\Commands;

use App\Models\Material;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditMaterialDuplicatesCommand extends Command
{
    protected $signature = 'materials:audit-duplicates
        {--limit=50 : Maximum groups to display per section}
        {--exact : Show only exact duplicate groups}
        {--json : Output machine-readable JSON}';

    protected $description = 'Dry-run audit of potential duplicate materials. Does not modify data.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $exactOnly = (bool) $this->option('exact');

        $sections = [
            'by_search_name' => $this->duplicateGroups(['search_name'], $limit),
            'by_type_search_name' => $this->duplicateGroups(['type', 'search_name'], $limit),
            'by_type_search_name_dimensions' => $this->duplicateGroups([
                'type',
                'search_name',
                'length_mm',
                'width_mm',
                'thickness_mm',
                'thickness',
            ], $limit),
            'exact' => $this->duplicateGroups([
                'type',
                'search_name',
                'length_mm',
                'width_mm',
                'thickness_mm',
                'thickness',
                'article',
                'user_id',
                'visibility',
            ], $limit),
        ];

        $summary = [
            'total_materials' => Material::query()->count(),
            'duplicate_groups_by_search_name' => $this->duplicateGroupCount(['search_name']),
            'duplicate_groups_by_type_search_name' => $this->duplicateGroupCount(['type', 'search_name']),
            'duplicate_groups_by_type_search_name_dimensions' => $this->duplicateGroupCount([
                'type',
                'search_name',
                'length_mm',
                'width_mm',
                'thickness_mm',
                'thickness',
            ]),
            'exact_duplicate_groups' => $this->duplicateGroupCount([
                'type',
                'search_name',
                'length_mm',
                'width_mm',
                'thickness_mm',
                'thickness',
                'article',
                'user_id',
                'visibility',
            ]),
        ];

        if ($this->option('json')) {
            $payload = [
                'summary' => $summary,
                'groups' => $exactOnly ? ['exact' => $sections['exact']] : $sections,
            ];
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Material duplicate audit (dry-run)');
        $this->line('No materials will be changed.');
        $this->newLine();

        if ($exactOnly) {
            $this->renderSection('Exact duplicate groups', $sections['exact']);
        } else {
            $this->renderSection('Potential groups by search_name', $sections['by_search_name']);
            $this->renderSection('Potential groups by type + search_name', $sections['by_type_search_name']);
            $this->renderSection('Potential groups by type + search_name + dimensions', $sections['by_type_search_name_dimensions']);
            $this->renderSection('Exact duplicate groups', $sections['exact']);
        }

        $this->newLine();
        $this->info('Summary');
        $this->line("Total materials: {$summary['total_materials']}");
        $this->line("Duplicate groups by search_name: {$summary['duplicate_groups_by_search_name']}");
        $this->line("Duplicate groups by type + search_name: {$summary['duplicate_groups_by_type_search_name']}");
        $this->line("Duplicate groups by type + search_name + dimensions: {$summary['duplicate_groups_by_type_search_name_dimensions']}");
        $this->line("Exact duplicate groups: {$summary['exact_duplicate_groups']}");

        return self::SUCCESS;
    }

    private function duplicateGroupCount(array $columns): int
    {
        return DB::query()
            ->fromSub($this->baseGroupedQuery($columns), 'duplicate_groups')
            ->count();
    }

    private function duplicateGroups(array $columns, int $limit): array
    {
        $groups = $this->baseGroupedQuery($columns)
            ->orderByDesc('cnt')
            ->limit($limit)
            ->get();

        return $groups->map(function ($group) use ($columns) {
            $materials = $this->materialsForGroup($columns, $group);

            return [
                'key' => collect($columns)->mapWithKeys(fn (string $column) => [$column => $group->{$column}])->all(),
                'count' => (int) $group->cnt,
                'materials' => $materials->map(fn (Material $material) => [
                    'id' => $material->id,
                    'name' => $material->name,
                    'type' => $material->type,
                    'article' => $material->article,
                    'length_mm' => $material->length_mm,
                    'width_mm' => $material->width_mm,
                    'thickness_mm' => $material->thickness_mm,
                    'thickness' => $material->thickness,
                    'user_id' => $material->user_id,
                    'visibility' => $material->visibility,
                    'origin' => $material->origin,
                    'created_at' => optional($material->created_at)->toDateTimeString(),
                    'updated_at' => optional($material->updated_at)->toDateTimeString(),
                ])->all(),
            ];
        })->all();
    }

    private function baseGroupedQuery(array $columns)
    {
        return DB::table('materials')
            ->whereNotNull('search_name')
            ->select(array_merge($columns, [DB::raw('COUNT(*) as cnt')]))
            ->groupBy($columns)
            ->having('cnt', '>', 1);
    }

    private function materialsForGroup(array $columns, object $group)
    {
        $query = Material::query();

        foreach ($columns as $column) {
            $value = $group->{$column};
            if ($value === null) {
                $query->whereNull($column);
                continue;
            }

            $query->where($column, $value);
        }

        return $query->orderBy('id')->get();
    }

    private function renderSection(string $title, array $groups): void
    {
        $this->newLine();
        $this->comment($title);

        if ($groups === []) {
            $this->line('  No groups found.');
            return;
        }

        foreach ($groups as $index => $group) {
            $this->line(sprintf('  #%d count=%d key=%s', $index + 1, $group['count'], json_encode($group['key'], JSON_UNESCAPED_UNICODE)));

            $rows = collect($group['materials'])->map(fn (array $material) => [
                $material['id'],
                $material['type'],
                $material['name'],
                $material['article'],
                $this->formatDimensions($material),
                $material['user_id'] ?? 'NULL',
                $material['visibility'] ?? 'NULL',
                $material['created_at'],
            ])->all();

            $this->table(['id', 'type', 'name', 'article', 'dimensions', 'user_id', 'visibility', 'created_at'], $rows);
        }
    }

    private function formatDimensions(array $material): string
    {
        return implode(' / ', [
            'L=' . ($material['length_mm'] ?? 'NULL'),
            'W=' . ($material['width_mm'] ?? 'NULL'),
            'Tmm=' . ($material['thickness_mm'] ?? 'NULL'),
            'T=' . ($material['thickness'] ?? 'NULL'),
        ]);
    }
}
