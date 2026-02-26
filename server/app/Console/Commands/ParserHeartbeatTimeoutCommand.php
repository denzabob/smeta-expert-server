<?php

namespace App\Console\Commands;

use App\Models\ParsingSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ParserHeartbeatTimeoutCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'parser:heartbeat-timeout 
                            {--timeout=10 : Heartbeat timeout в минутах}
                            {--dry-run : Показать что будет завершено без внесения изменений}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detect and fail parsing sessions with stale heartbeat';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $timeout = (int)$this->option('timeout');
        $dryRun = $this->option('dry-run');

        $this->info("⏱️  Проверка сессий с timeout > {$timeout} минут...");

        if ($dryRun) {
            $this->warn('⚠️  DRY-RUN режим: никакие изменения не будут произведены');
        }

        $staleSessions = ParsingSession::where('status', 'running')
            ->whereNotNull('last_heartbeat_at')
            ->where('last_heartbeat_at', '<', now()->subMinutes($timeout))
            ->get();

        if ($staleSessions->isEmpty()) {
            $this->info('✅ Нет сессий с timeout');
            return Command::SUCCESS;
        }

        $this->info("Найдено сессий с timeout: {$staleSessions->count()}");

        $completed = 0;
        $failed = 0;

        foreach ($staleSessions as $session) {
            $minutesStale = now()->diffInMinutes($session->last_heartbeat_at);
            $message = "Session #{$session->id} ({$session->supplier_name}): "
                . "нет heartbeat'а {$minutesStale} минут (timeout {$timeout})";

            if ($dryRun) {
                $this->line("  [DRY-RUN] ⏱️  {$message}");
                $completed++;
                continue;
            }

            try {
                $session->markAsFailed(
                    "Heartbeat timeout: no callbacks for {$minutesStale} minutes",
                    -2 // Special exit code для timeout
                );

                Log::warning("Parser session timeout: {$message}");
                $this->line("  ⏱️  ✅ {$message}");
                $completed++;
            } catch (\Exception $e) {
                $this->error("  ❌ Ошибка: {$e->getMessage()}");
                Log::error("Error failing timeout session {$session->id}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("✅ Завершено сессий: {$completed}");
        if ($failed > 0) {
            $this->warn("❌ Ошибок при завершении: {$failed}");
        }

        return Command::SUCCESS;
    }
}
