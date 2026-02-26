<?php

namespace App\Console\Commands;

use App\Models\ProjectFitting;
use Illuminate\Console\Command;

class TestFittingUpdate extends Command
{
    protected $signature = 'test:fitting-update {fitting_id}';
    protected $description = 'Test fitting update';

    public function handle()
    {
        $fittingId = $this->argument('fitting_id');
        
        $fitting = ProjectFitting::find($fittingId);
        
        if (!$fitting) {
            $this->error("Fitting #{$fittingId} not found");
            return;
        }
        
        $this->info("Found fitting: {$fitting->name}");
        
        // Test update
        $fitting->name = $fitting->name . ' (updated at ' . now()->format('H:i:s') . ')';
        $fitting->save();
        
        $this->info("✓ Fitting updated successfully");
        $this->line("New name: {$fitting->name}");
    }
}
