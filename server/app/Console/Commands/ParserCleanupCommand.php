<?php

namespace App\Console\Commands;

use App\Models\ParsingSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ParserCleanupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'parser:cleanup 
                            {--aggressive : Режим агрессивной очистки (без проверки heartbeat)}
                            {--dry-run : Показать что будет удалено без внесения изменений}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanup zombie parsing processes and stale sessions';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $aggressive = $this->option('aggressive');

        $this->info('🧹 Запускается очистка зомби-процессов...');

        if ($dryRun) {
            $this->warn('⚠️  DRY-RUN режим: никакие изменения не будут произведены');
        }

        $cleaned = 0;
        $failed = 0;

        // 1. Ищем сессии со статусом running, чьи PID отсутствуют в системе
        $deadSessions = ParsingSession::where('status', 'running')
            ->whereNotNull('pid')
            ->get();

        foreach ($deadSessions as $session) {
            if ($this->isProcessDead($session->pid)) {
                $this->cleanupSession($session, 'Зомби-процесс (PID не найден)', $dryRun);
                $cleaned++;
            }
        }

        // 2. Ищем сессии без heartbeat'а более 10 минут
        if (!$aggressive) {
            $staleTimeout = 10; // минут
        } else {
            $staleTimeout = 3; // минут для агрессивного режима
        }

        $staleSessions = ParsingSession::where('status', 'running')
            ->whereNotNull('last_heartbeat_at')
            ->where('last_heartbeat_at', '<', now()->subMinutes($staleTimeout))
            ->get();

        foreach ($staleSessions as $session) {
            $minutesStale = now()->diffInMinutes($session->last_heartbeat_at);
            $this->cleanupSession(
                $session,
                "Нет heartbeat'а {$minutesStale} минут (timeout > {$staleTimeout} мин)",
                $dryRun
            );
            $cleaned++;
        }

        // 3. Ищем сессии running без PID и без started_at (висят в БД)
        $orphanedSessions = ParsingSession::where('status', 'running')
            ->whereNull('pid')
            ->where('started_at', '<', now()->subMinutes(30))
            ->get();

        foreach ($orphanedSessions as $session) {
            $this->cleanupSession($session, 'Сиротская сессия (нет PID, 30+ минут)', $dryRun);
            $cleaned++;
        }

        $this->info("✅ Очищено сессий: {$cleaned}");
        $this->info("❌ Ошибок при очистке: {$failed}");

        return Command::SUCCESS;
    }

    /**
     * Check if process is dead.
     */
    protected function isProcessDead(int $pid): bool
    {
        // На Linux/Unix
        if (PHP_OS_FAMILY === 'Linux') {
            return !file_exists("/proc/{$pid}");
        }

        // На Windows - используем более простую проверку
        if (PHP_OS_FAMILY === 'Windows') {
            $output = [];
            $exitCode = 0;
            exec("tasklist /FI \"PID eq {$pid}\" 2>NUL", $output, $exitCode);
            return count($output) < 2; // Если вывод меньше 2 строк, процесса нет
        }

        // Для других OS - предполагаем что процесс мертв если не можем проверить
        return true;
    }

    /**
     * Clean up a single session.
     */
    protected function cleanupSession(ParsingSession $session, string $reason, bool $dryRun): void
    {
        $message = "Session #{$session->id} ({$session->supplier_name}): {$reason};";

        if ($dryRun) {
            $this->line("  [DRY-RUN] {$message}");
            return;
        }

        try {
            $session->markAsFailed(
                "Auto-cleanup: {$reason}",
                -1 // Special exit code для auto-cleanup
            );

            Log::warning("Parser session auto-cleaned: {$message}");
            $this->line("  ✅ {$message}");
        } catch (\Exception $e) {
            $this->error("  ❌ Ошибка очистки: {$e->getMessage()}");
            Log::error("Error cleaning up parser session {$session->id}: {$e->getMessage()}");
        }
    }
}
