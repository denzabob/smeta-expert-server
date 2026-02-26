<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Operation;

class CheckOperationCategories extends Command
{
    protected $signature = 'check:operation-categories';
    protected $description = 'Check operation categories in database';

    public function handle()
    {
        $this->info("=== Проверка категорий операций в БД ===\n");
        
        // Операции резки
        $this->info("Операции резки (exclusion_group='cutting'):");
        $cuttingOps = Operation::where('exclusion_group', 'cutting')->get();
        if ($cuttingOps->isEmpty()) {
            $this->warn("  - Не найдены");
        } else {
            foreach ($cuttingOps as $op) {
                $this->line(sprintf(
                    "  - ID: %d, Имя: %s, Категория: %s",
                    $op->id,
                    $op->name,
                    $op->category
                ));
            }
        }
        
        // Операции кромкооблицовки
        $this->info("\nОперации кромкооблицовки (exclusion_group='edging'):");
        $edgingOps = Operation::where('exclusion_group', 'edging')->get();
        if ($edgingOps->isEmpty()) {
            $this->warn("  - Не найдены");
        } else {
            foreach ($edgingOps as $op) {
                $this->line(sprintf(
                    "  - ID: %d, Имя: %s, Категория: %s",
                    $op->id,
                    $op->name,
                    $op->category
                ));
            }
        }
        
        // Все уникальные категории
        $this->info("\nВсе уникальные категории в БД:");
        $categories = Operation::distinct('category')->orderBy('category')->pluck('category');
        if ($categories->isEmpty()) {
            $this->warn("  - Нет операций");
        } else {
            foreach ($categories as $cat) {
                $count = Operation::where('category', $cat)->count();
                $this->line(sprintf("  - %s (%d операций)", $cat, $count));
            }
        }
    }
}
