<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\CollectUrlsJob;
use Illuminate\Support\Facades\Log;

class CollectUrlsByCategories extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'collect:urls-chunked 
                            {supplier : Supplier name (e.g., skm_mebel)}
                            {--session= : Optional session ID for logging}
                            {--categories=* : Specific categories to process (optional)}';

    /**
     * The console command description.
     */
    protected $description = 'Collect URLs in chunks by categories (prevents timeout)';

    /**
     * Список категорий для SKM Mebel
     */
    protected array $skmMebelCategories = [
        'dsp_laminirovannaya',  // ЛДСП
        'mdf',                  // МДФ  
        'khdf',                 // ХДФ
        'dsp',                  // ДСП
        'dsp_shpuntovannaya',   // ДСП Шпунтованная
        'dvp',                  // ДВП
        'fanera1',              // Фанера
        'lmdf',                 // ЛМДФ
        'kromochnye_materialy', // Кромка мебельная
        'osb',                  // OSB
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $supplier = $this->argument('supplier');
        $sessionId = $this->option('session');
        $categories = $this->option('categories');

        // Проверяем конфиг
        $configPath = base_path("parser/configs/{$supplier}.json");
        
        if (!file_exists($configPath)) {
            $this->error("Config not found for supplier: {$supplier}");
            return Command::FAILURE;
        }

        $config = json_decode(file_get_contents($configPath), true);
        
        if (!$config) {
            $this->error("Failed to parse config for: {$supplier}");
            return Command::FAILURE;
        }

        // Определяем категории для обработки
        $categoriesToProcess = [];
        
        if (!empty($categories)) {
            // Используем указанные категории
            $categoriesToProcess = $categories;
            $this->info("Processing specified categories: " . implode(', ', $categories));
        } elseif ($supplier === 'skm_mebel') {
            // Для SKM Mebel используем предопределённый список
            $categoriesToProcess = $this->skmMebelCategories;
            $this->info("Processing all SKM Mebel material categories");
        } else {
            $this->error("No categories specified and no default categories for supplier: {$supplier}");
            $this->info("Use --categories option to specify categories");
            return Command::FAILURE;
        }

        $baseUrl = $config['base_url'] ?? 'https://skm-mebel.ru';
        $totalJobs = count($categoriesToProcess);

        $this->info("Dispatching {$totalJobs} jobs to queue...");
        $this->newLine();

        $bar = $this->output->createProgressBar($totalJobs);
        $bar->start();

        foreach ($categoriesToProcess as $category) {
            $categoryUrl = rtrim($baseUrl, '/') . '/category/' . $category . '/';
            
            // Диспатчим Job для каждой категории
            CollectUrlsJob::dispatch($supplier, $sessionId ? (int)$sessionId : null, $categoryUrl);
            
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("✓ {$totalJobs} jobs dispatched successfully");
        $this->info("Monitor progress in queue worker logs: docker-compose logs -f worker");
        $this->newLine();
        $this->comment("Jobs are processing in background. URL collection will complete asynchronously.");

        Log::info("Chunked URL collection dispatched", [
            'supplier' => $supplier,
            'session_id' => $sessionId,
            'categories_count' => $totalJobs,
            'categories' => $categoriesToProcess,
        ]);

        return Command::SUCCESS;
    }
}
