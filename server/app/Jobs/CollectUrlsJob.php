<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Log;
use App\Models\ParsingSession;

class CollectUrlsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Таймаут выполнения джоба в секундах (30 минут).
     */
    public $timeout = 1800;

    /**
     * Количество попыток выполнения.
     */
    public $tries = 1;

    /**
     * @param string $supplier Имя поставщика
     * @param int|null $sessionId ID сессии парсинга (опционально)
     * @param string|null $categoryUrl URL категории для сбора (опционально, для chunked сбора)
     */
    public function __construct(
        public string $supplier,
        public ?int $sessionId = null,
        public ?string $categoryUrl = null,
        public ?string $configOverrideBase64 = null,
        public ?string $apiUrl = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("CollectUrlsJob started", [
            'supplier' => $this->supplier,
            'session_id' => $this->sessionId,
            'category_url' => $this->categoryUrl,
        ]);

        // Проверяем конфиг
        $configPath = base_path("parser/configs/{$this->supplier}.json");
        
        if (!file_exists($configPath)) {
            Log::error("Config not found", ['supplier' => $this->supplier]);
            throw new \Exception("Config not found for supplier: {$this->supplier}");
        }

        $config = json_decode(file_get_contents($configPath), true);

        if ($this->configOverrideBase64) {
            try {
                $decoded = base64_decode($this->configOverrideBase64, true);
                $override = json_decode($decoded ?: '', true);
                if (is_array($override)) {
                    $config = array_replace_recursive($config, $override);
                }
            } catch (\Throwable $e) {
                Log::warning("Invalid config override", [
                    'supplier' => $this->supplier,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        if (!$config || !($config['collect_urls'] ?? false)) {
            Log::warning("URL collection disabled", ['supplier' => $this->supplier]);
            return;
        }

        // Получаем HMAC секрет
        $hmacSecret = env('PARSER_HMAC_SECRET') ?? config('parser.hmac_secret');
        
        if (!$hmacSecret) {
            throw new \Exception("HMAC secret not configured");
        }

        // Формируем команду
        $pythonPath = env('PARSER_PYTHON_PATH', config('parser.python_executable', 'python3'));
        $scriptPath = base_path('parser/collect_urls.py');
        
        $command = [
            $pythonPath,
            $scriptPath,
            '--supplier', $this->supplier,
            '--hmac-secret', $hmacSecret,
        ];
        
        if ($this->sessionId) {
            $command[] = '--session';
            $command[] = (string)$this->sessionId;
        }

        if ($this->configOverrideBase64) {
            $command[] = '--config-override-base64';
            $command[] = $this->configOverrideBase64;
        }

        if ($this->apiUrl) {
            $command[] = '--api-url';
            $command[] = $this->apiUrl;
        }

        // Если передан URL категории - добавляем параметр для сбора только этой категории
        if ($this->categoryUrl) {
            $command[] = '--category-url';
            $command[] = $this->categoryUrl;
        }

        Log::info("Executing Python script", [
            'supplier' => $this->supplier,
            'has_category' => !is_null($this->categoryUrl),
        ]);

        // Запускаем процесс с увеличенным таймаутом
        $result = Process::timeout(1800)->run($command);

        if (!$result->successful()) {
            $errorOutput = $result->errorOutput();
            Log::error("URL collection failed", [
                'supplier' => $this->supplier,
                'exit_code' => $result->exitCode(),
                'error' => $errorOutput,
            ]);
            
            throw new \Exception("URL collection failed: {$errorOutput}");
        }

        $output = $result->output();
        Log::info("URL collection completed", [
            'supplier' => $this->supplier,
            'output_length' => strlen($output),
        ]);

        // Обновляем статус сессии, если передан session_id
        if ($this->sessionId) {
            $session = ParsingSession::find($this->sessionId);
            if ($session) {
                // Получаем количество URL из вывода
                if (preg_match('/Total:\s*(\d+)/i', $output, $matches)) {
                    $session->update([
                        'total_urls' => (int)$matches[1],
                        'status' => 'urls_collected',
                    ]);
                }
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("CollectUrlsJob failed", [
            'supplier' => $this->supplier,
            'session_id' => $this->sessionId,
            'category_url' => $this->categoryUrl,
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Обновляем статус сессии на failed
        if ($this->sessionId) {
            $session = ParsingSession::find($this->sessionId);
            if ($session) {
                $session->update([
                    'status' => 'failed',
                    'error_message' => $exception->getMessage(),
                ]);
            }
        }
    }
}
