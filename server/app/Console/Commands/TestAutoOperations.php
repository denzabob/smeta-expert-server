<?php
/**
 * Artisan 命令来测试自动操作计算
 */

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Project;
use App\Services\Smeta\SmetaCalculator;

class TestAutoOperations extends Command
{
    protected $signature = 'test:auto-operations {project_id=1}';
    protected $description = 'Test automatic operations calculation (раскрой ДСП and кромкооблицовка)';

    public function handle()
    {
        $projectId = $this->argument('project_id');
        
        $project = Project::find($projectId);
        if (!$project) {
            $this->error("项目 ID {$projectId} 不存在");
            return 1;
        }
        
        $this->info("=== 测试项目: {$project->number} ===\n");
        
        $calculator = new SmetaCalculator();
        
        $this->info("获取操作数据...");
        $operations = $calculator->calculateOperationData($project);
        
        $this->info("\n发现 " . count($operations) . " 个操作:\n");
        
        $this->line(str_repeat("-", 120));
        $this->line(sprintf(
            "%-40s | %-12s | %10s %4s | %12s | %15s | %12s",
            "名称",
            "类别",
            "数量",
            "单位",
            "单价",
            "是否手动",
            "总计"
        ));
        $this->line(str_repeat("-", 120));
        
        foreach ($operations as $op) {
            $this->line(sprintf(
                "%-40s | %-12s | %10.2f %4s | %12.2f | %15s | %12.2f",
                substr($op->name, 0, 40),
                $op->category,
                $op->quantity,
                $op->unit,
                $op->cost_per_unit,
                $op->is_manual ? "是" : "否",
                $op->total_cost
            ));
        }
        
        $this->line(str_repeat("-", 120));
        
        // 统计各类别的操作
        $byCategory = [];
        foreach ($operations as $op) {
            if (!isset($byCategory[$op->category])) {
                $byCategory[$op->category] = [];
            }
            $byCategory[$op->category][] = $op;
        }
        
        $this->info("\n按类别统计:");
        foreach ($byCategory as $category => $ops) {
            $totalCost = array_reduce($ops, fn($sum, $op) => $sum + $op->total_cost, 0);
            $this->line(sprintf(
                "  %s: %d 个操作，总成本: %.2f",
                $category,
                count($ops),
                $totalCost
            ));
        }
        
        // 检查是否有 cutting 和 edging операций
        $this->info("\nПроверка автоматических операций:");
        $hasCutting = isset($byCategory['Обработка плитных материалов']) && !empty($byCategory['Обработка плитных материалов']);
        $hasEdging = isset($byCategory['Кромкооблицовка']) && !empty($byCategory['Кромкооблицовка']);
        
        if ($hasCutting) {
            $this->line("  ✓ Раскрой ДСП (Обработка плитных материалов): присутствует");
        } else {
            $this->warn("  ✗ Раскрой ДСП: отсутствует");
        }
        
        if ($hasEdging) {
            $this->line("  ✓ Кромкооблицовка деталей: присутствует");
        } else {
            $this->warn("  ✗ Кромкооблицовка: отсутствует");
        }
        
        $this->info("\n成功！");
        return 0;
    }
}
