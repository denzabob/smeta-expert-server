<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ShowLogs extends Command
{
    protected $signature = 'logs:show {lines=50}';
    protected $description = 'Show recent logs';

    public function handle()
    {
        $logFile = storage_path('logs/laravel.log');
        
        if (!File::exists($logFile)) {
            $this->error('Log file not found');
            return;
        }
        
        $lines = intval($this->argument('lines'));
        $content = File::get($logFile);
        $logLines = explode("\n", $content);
        
        // Get last N lines
        $logLines = array_slice($logLines, -$lines);
        
        foreach ($logLines as $line) {
            if (trim($line)) {
                $this->line($line);
            }
        }
    }
}
