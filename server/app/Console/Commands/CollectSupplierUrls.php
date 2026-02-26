<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Log;
use App\Models\ParsingSession;

class CollectSupplierUrls extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'collect:urls 
                            {supplier : Supplier name (e.g., skm_mebel)}
                            {--queue : Dispatch to queue instead of running directly}
                            {--async : Run in background (return immediately)}
                            {--session= : Optional session ID for logging}
                            {--config-override-base64= : Base64 JSON config override for collect URLs}
                            {--api-url= : Base API URL (e.g., http://localhost/api)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Collect and validate URLs from supplier catalog';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $supplier = $this->argument('supplier');
        $sessionId = $this->option('session');
        $async = $this->option('async');
        $useQueue = $this->option('queue');
        $configOverrideBase64 = $this->option('config-override-base64');
        $apiUrl = $this->option('api-url');
        
        $this->info("Starting URL collection for: {$supplier}");
        
        // Проверяем, есть ли конфиг для поставщика
        // В Docker: parser монтирован в /var/www/html/parser
        $configPath = base_path("parser/configs/{$supplier}.json");
        
        if (!file_exists($configPath)) {
            $this->error("Config not found for supplier: {$supplier}");
            $this->error("Expected path: {$configPath}");
            return Command::FAILURE;
        }
        
        // Загружаем конфиг и проверяем, включён ли сбор URL
        $config = json_decode(file_get_contents($configPath), true);
        
        if (!$config) {
            $this->error("Failed to parse config for: {$supplier}");
            return Command::FAILURE;
        }
        
        if ($configOverrideBase64) {
            try {
                $decoded = base64_decode($configOverrideBase64, true);
                $override = json_decode($decoded ?: '', true);
                if (is_array($override)) {
                    $config = array_replace_recursive($config, $override);
                }
            } catch (\Throwable $e) {
                $this->warn("Invalid config override: {$e->getMessage()}");
            }
        }

        if (!($config['collect_urls'] ?? false)) {
            $this->warn("URL collection disabled in config for: {$supplier}");
            return Command::SUCCESS;
        }

        // Если указан --queue, отправляем в очередь
        if ($useQueue) {
            $this->info("Dispatching URL collection to queue...");
            \App\Jobs\CollectUrlsJob::dispatch(
                $supplier,
                $sessionId ? (int)$sessionId : null,
                null,
                $configOverrideBase64 ?: null,
                $apiUrl ?: null
            );
            $this->info("✓ Job dispatched successfully. Check queue worker logs for progress.");
            return Command::SUCCESS;
        }
        
        // Получаем HMAC секрет
        $hmacSecret = env('PARSER_HMAC_SECRET') ?? config('parser.hmac_secret');
        
        if (!$hmacSecret) {
            $this->error("HMAC secret not configured. Set PARSER_HMAC_SECRET in .env");
            return Command::FAILURE;
        }
        
        // Формируем команду для Python
        $pythonPath = env('PARSER_PYTHON_PATH', config('parser.python_executable', 'python3'));
        // В Docker: parser монтирован в /var/www/html/parser
        $scriptPath = base_path('parser/collect_urls.py');
        
        $command = [
            $pythonPath,
            $scriptPath,
            '--supplier', $supplier,
            '--hmac-secret', $hmacSecret,
        ];
        
        if ($sessionId) {
            $command[] = '--session';
            $command[] = $sessionId;
        }

        if ($configOverrideBase64) {
            $command[] = '--config-override-base64';
            $command[] = $configOverrideBase64;
        }

        if ($apiUrl) {
            $command[] = '--api-url';
            $command[] = $apiUrl;
        }
        
        $this->line("Command: " . implode(' ', array_map(function($arg) use ($hmacSecret) {
            return $arg === $hmacSecret ? '***' : $arg;
        }, $command)));
        
        try {
            if ($async) {
                // Асинхронный запуск
                $this->info("Starting collection in background...");
                
                // Логируем команду перед запуском
                Log::info("Starting async URL collection", [
                    'supplier' => $supplier,
                    'session' => $sessionId,
                    'command' => implode(' ', array_map(function($arg) use ($hmacSecret) {
                        return $arg === $hmacSecret ? '***' : escapeshellarg($arg);
                    }, $command))
                ]);
                
                $process = Process::start($command);
                
                // Ждём немного чтобы убедиться, что процесс запустился
                sleep(1);
                
                // Проверяем, запущен ли процесс
                if ($process->running()) {
                    $this->info("✓ Collection process started successfully (PID: " . $process->id() . ")");
                    Log::info("URL collection process started", [
                        'supplier' => $supplier,
                        'session' => $sessionId,
                        'pid' => $process->id(),
                    ]);
                } else {
                    $exitCode = $process->exitCode();
                    $output = $process->output();
                    $errorOutput = $process->errorOutput();
                    
                    $this->error("✗ Collection process failed to start or exited immediately");
                    $this->error("Exit code: {$exitCode}");
                    
                    if ($output) {
                        $this->line("Output: " . $output);
                    }
                    if ($errorOutput) {
                        $this->error("Error: " . $errorOutput);
                    }
                    
                    Log::error("URL collection process failed", [
                        'supplier' => $supplier,
                        'session' => $sessionId,
                        'exit_code' => $exitCode,
                        'output' => $output,
                        'error' => $errorOutput,
                    ]);
                    
                    return Command::FAILURE;
                }
                
                return Command::SUCCESS;
                
            } else {
                // Синхронный запуск с увеличенным таймаутом
                $result = Process::timeout(3600)  // 60 минут
                    ->run($command);
                
                // Выводим stdout
                if ($result->output()) {
                    $this->line($result->output());
                }
                
                // Выводим stderr (там логи Python)
                if ($result->errorOutput()) {
                    $this->comment("--- Python Logs ---");
                    $this->line($result->errorOutput());
                }
                
                if ($result->successful()) {
                    $this->info("✓ Collection completed successfully");
                    
                    Log::info("URL collection completed", [
                        'supplier' => $supplier,
                        'session' => $sessionId,
                        'exit_code' => $result->exitCode(),
                    ]);
                    
                    return Command::SUCCESS;
                } else {
                    $this->error("✗ Collection failed");
                    
                    Log::error("URL collection failed", [
                        'supplier' => $supplier,
                        'session' => $sessionId,
                        'exit_code' => $result->exitCode(),
                        'error' => $result->errorOutput(),
                    ]);
                    
                    return Command::FAILURE;
                }
            }
            
        } catch (\Exception $e) {
            $this->error("Exception during collection: " . $e->getMessage());
            
            Log::error("URL collection exception", [
                'supplier' => $supplier,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return Command::FAILURE;
        }
    }
}
