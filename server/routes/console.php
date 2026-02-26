<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * ==================== ANTI-LOOP ARCHITECTURE ====================
 * 
 * IMPORTANT: Automatic URL collection is DISABLED.
 * 
 * Parsing lifecycle is now DETERMINISTIC:
 * 1. Parsing starts ONLY via explicit API request:
 *    POST /api/parsing/sessions {"supplier": "skm_mebel"}
 * 
 * 2. Each session runs collect → reset → parse EXACTLY ONCE
 * 
 * 3. On failure/timeout: session status → 'failed' (NO auto-restart)
 * 
 * 4. To retry failed URLs:
 *    POST /api/parsing/sessions/{id}/retry-failed-urls
 * 
 * The scheduled task below is COMMENTED OUT intentionally.
 * If periodic collection is needed, it should:
 * - Create a NEW session via API
 * - NOT auto-restart on failure
 * =================================================================
 */

/*
 * DISABLED: Periodic URL collection.
 * 
 * This code is preserved for reference but MUST NOT be uncommented
 * without understanding the anti-loop architecture.
 * 
 * Recommended approach: Use external cron to call API endpoint instead.
 * 
Schedule::call(function () {
    $configsPath = base_path('parser/configs');
    
    if (!is_dir($configsPath)) {
        \Illuminate\Support\Facades\Log::warning("Parser configs directory not found: {$configsPath}");
        return;
    }
    
    $configFiles = glob("{$configsPath}/*.json");
    
    foreach ($configFiles as $configFile) {
        $supplierName = basename($configFile, '.json');
        
        // Пропускаем template.json
        if ($supplierName === 'template') {
            continue;
        }
        
        try {
            $config = json_decode(file_get_contents($configFile), true);
            
            if (!$config) {
                \Illuminate\Support\Facades\Log::error("Failed to parse config: {$configFile}");
                continue;
            }
            
            // Проверяем, включён ли сбор URL
            if (!($config['collect_urls'] ?? false)) {
                continue;
            }
            
            // Проверяем наличие параметра frequency
            $frequency = $config['url_collection_frequency'] ?? null;
            
            if (!$frequency) {
                continue;
            }
            
            // Планируем задачу согласно frequency
            $task = null;
            
            switch ($frequency) {
                case 'daily':
                    $task = Schedule::command('collect:urls', [$supplierName])
                        ->daily()
                        ->at('02:00');
                    break;
                    
                case 'weekly':
                    $task = Schedule::command('collect:urls', [$supplierName])
                        ->weekly()
                        ->mondays()
                        ->at('02:00');
                    break;
                    
                case 'monthly':
                    $task = Schedule::command('collect:urls', [$supplierName])
                        ->monthly()
                        ->at('02:00');
                    break;
                    
                case 'yearly':
                    $task = Schedule::command('collect:urls', [$supplierName])
                        ->yearly()
                        ->at('02:00');
                    break;
                    
                default:
                    if (preg_match('/^[\d\*\,\-\/\s]+$/', $frequency)) {
                        $task = Schedule::command('collect:urls', [$supplierName])
                            ->cron($frequency);
                    } else {
                        continue 2;
                    }
            }
            
            if ($task) {
                $task->withoutOverlapping()
                     ->runInBackground()
                     ->onOneServer();
            }
            
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Error scheduling collection for {$supplierName}", [
                'error' => $e->getMessage(),
            ]);
        }
    }
})->name('schedule-url-collection')->everyMinute();
*/