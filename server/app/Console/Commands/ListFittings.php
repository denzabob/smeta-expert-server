<?php

namespace App\Console\Commands;

use App\Models\ProjectFitting;
use Illuminate\Console\Command;

class ListFittings extends Command
{
    protected $signature = 'list:fittings';
    protected $description = 'List all project fittings with their projects';

    public function handle()
    {
        $fittings = ProjectFitting::all();
        
        $this->info("Total fittings: {$fittings->count()}");
        $this->line("");
        
        foreach ($fittings as $fitting) {
            $this->line("Fitting #{$fitting->id}:");
            $this->line("  Name: {$fitting->name}");
            $this->line("  Project ID (column): {$fitting->project_id}");
            
            $project = $fitting->project;
            if ($project) {
                $this->line("  ✓ Project loaded:");
                $this->line("    ID: {$project->id}");
                $this->line("    User ID: {$project->user_id}");
            } else {
                $this->line("  ✗ Project relation is null");
            }
        }
    }
}
