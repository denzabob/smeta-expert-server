<?php

namespace App\Console\Commands;

use App\Jobs\Parsing\StartFullScanJob;
use App\Models\ParsingSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Start parsing session for a supplier.
 * 
 * Uses new phase-based job architecture:
 * StartFullScanJob → CollectUrlsJob → ResetUrlsJob → ParseBatchJob (loop)
 */
class StartParsing extends Command
{
    protected $signature = 'parsing:start 
                            {supplier : Supplier name (e.g., skm_mebel)}
                            {--full-scan : Run full scan (collect + reset + parse)}
                            {--collect-only : Only collect URLs}
                            {--parse-only : Only parse (skip collect/reset)}
                            {--max-pages= : Max pages per category for collect}
                            {--max-urls= : Max URLs to collect}
                            {--max-time= : Max collect time in seconds}
                            {--session= : Resume existing session ID}';

    protected $description = 'Start a parsing session for a supplier';

    public function handle(): int
    {
        $supplier = $this->argument('supplier');
        $fullScan = $this->option('full-scan');
        $collectOnly = $this->option('collect-only');
        $parseOnly = $this->option('parse-only');
        $sessionId = $this->option('session');

        $this->info("Starting parsing for supplier: {$supplier}");

        // Build config
        $config = [];
        
        if ($this->option('max-pages')) {
            $config['max_collect_pages'] = (int) $this->option('max-pages');
        }
        if ($this->option('max-urls')) {
            $config['max_collect_urls'] = (int) $this->option('max-urls');
        }
        if ($this->option('max-time')) {
            $config['max_collect_time_seconds'] = (int) $this->option('max-time');
        }

        // Check for existing active session
        $existingSession = ParsingSession::where('supplier_name', $supplier)
            ->whereIn('status', [ParsingSession::DB_STATUS_PENDING, ParsingSession::DB_STATUS_RUNNING])
            ->first();

        if ($existingSession && !$sessionId) {
            $this->warn("Active session exists: #{$existingSession->id}");
            $this->warn("Status: {$existingSession->status}, Lifecycle: {$existingSession->getLifecycleStatus()}");
            
            if (!$this->confirm('Do you want to resume this session?')) {
                $this->error('Aborted. Use --session={id} to resume or wait for session to complete.');
                return 1;
            }
            
            $sessionId = $existingSession->id;
        }

        // Dispatch the orchestrator job
        try {
            if ($fullScan || (!$collectOnly && !$parseOnly)) {
                $this->info('Dispatching StartFullScanJob (full pipeline)...');
                StartFullScanJob::dispatch($supplier, $config, $sessionId ? (int) $sessionId : null)
                    ->onQueue('parsing');
            } elseif ($collectOnly) {
                $this->info('Dispatching CollectUrlsJob (collect only)...');
                
                $session = $sessionId 
                    ? ParsingSession::findOrFail($sessionId)
                    : ParsingSession::create([
                        'supplier_name' => $supplier,
                        'status' => ParsingSession::DB_STATUS_PENDING,
                        'lifecycle_status' => ParsingSession::LIFECYCLE_CREATED,
                        'max_collect_pages' => $config['max_collect_pages'] ?? null,
                        'max_collect_urls' => $config['max_collect_urls'] ?? null,
                        'max_collect_time_seconds' => $config['max_collect_time_seconds'] ?? null,
                    ]);
                
                \App\Jobs\Parsing\CollectUrlsJob::dispatch($session, $supplier, $config)
                    ->onQueue('parsing');
            } elseif ($parseOnly) {
                $this->info('Dispatching ParseBatchJob (parse only)...');
                
                if (!$sessionId) {
                    $this->error('--parse-only requires --session={id}');
                    return 1;
                }
                
                $session = ParsingSession::findOrFail($sessionId);
                
                if ($session->canStartParsing()) {
                    $session->startParsing();
                }
                
                \App\Jobs\Parsing\ParseBatchJob::dispatch($session, $supplier, $config)
                    ->onQueue('parsing');
            }

            $this->info('Job dispatched successfully!');
            $this->info('Monitor progress with: php artisan queue:work --queue=parsing');
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error("Failed to dispatch job: {$e->getMessage()}");
            Log::error('StartParsing command failed', [
                'supplier' => $supplier,
                'exception' => $e->getMessage(),
            ]);
            return 1;
        }
    }
}
