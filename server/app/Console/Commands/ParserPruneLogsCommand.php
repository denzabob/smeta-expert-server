<?php

namespace App\Console\Commands;

use App\Models\ParsingLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ParserPruneLogsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'parser:prune-logs 
                            {--days=14 : Удалять логи старше N дней}
                            {--dry-run : Показать что будет удалено без внесения изменений}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete old parsing logs to prevent database bloat';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int)$this->option('days');
        $dryRun = $this->option('dry-run');

        $this->info("🗑️  Удаление логов старше {$days} дней...");

        if ($dryRun) {
            $this->warn('⚠️  DRY-RUN режим: никакие изменения не будут произведены');
        }

        // Находим логи для удаления
        $cutoffDate = now()->subDays($days);
        
        $query = ParsingLog::where('created_at', '<', $cutoffDate);
        $count = $query->count();

        if ($count === 0) {
            $this->info('✅ Нет логов для удаления');
            return Command::SUCCESS;
        }

        $this->info("Найдено логов к удалению: {$count}");

        if ($dryRun) {
            // Показываем статистику по сессиям
            $sessionStats = ParsingLog::where('created_at', '<', $cutoffDate)
                ->groupBy('parsing_session_id')
                ->selectRaw('parsing_session_id, COUNT(*) as count')
                ->orderByDesc('count')
                ->limit(10)
                ->get();

            $this->line("\n📊 Топ 10 сессий по количеству логов к удалению:");
            foreach ($sessionStats as $stat) {
                $this->line("  Session #{$stat->parsing_session_id}: {$stat->count} логов");
            }

            return Command::SUCCESS;
        }

        // Выполняем удаление
        try {
            $deleted = $query->delete();
            
            Log::info("Parser logs pruned: {$deleted} records deleted");
            $this->info("✅ Удалено логов: {$deleted}");

            // Оптимизируем таблицу
            $this->line('Оптимизация таблицы...');
            \DB::statement('OPTIMIZE TABLE parsing_logs');
            $this->info('✅ Таблица оптимизирована');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("❌ Ошибка при удалении логов: {$e->getMessage()}");
            Log::error("Error pruning parser logs: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
