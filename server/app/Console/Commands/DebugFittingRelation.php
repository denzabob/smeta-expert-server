<?php

namespace App\Console\Commands;

use App\Models\ProjectFitting;
use App\Models\Project;
use Illuminate\Console\Command;

class DebugFittingRelation extends Command
{
    protected $signature = 'debug:fitting-relation {fitting_id?}';
    protected $description = 'Debug ProjectFitting relation to Project';

    public function handle()
    {
        $fittingId = $this->argument('fitting_id') ?? 1;
        
        // Fetch fitting
        $fitting = ProjectFitting::find($fittingId);
        
        if (!$fitting) {
            $this->error("Fitting #{$fittingId} not found");
            return;
        }
        
        $this->info("=== ProjectFitting #{$fittingId} ===");
        $this->line("ID: {$fitting->id}");
        $this->line("Name: {$fitting->name}");
        $this->line("Project ID (column): {$fitting->project_id}");
        
        // Try loading relation
        $project = $fitting->project;
        
        if ($project) {
            $this->info("✓ Project loaded via relation:");
            $this->line("  Project ID: {$project->id}");
            $this->line("  Project Number: {$project->number}");
            $this->line("  Project User ID: {$project->user_id}");
        } else {
            $this->error("✗ Project is null via relation");
            
            // Try direct query
            $directProject = Project::find($fitting->project_id);
            if ($directProject) {
                $this->warn("  But direct query works:");
                $this->line("  Project ID: {$directProject->id}");
                $this->line("  Project User ID: {$directProject->user_id}");
            } else {
                $this->error("  Direct query also fails");
            }
        }
        
        // Try with eager loading
        $fitting2 = ProjectFitting::with('project')->find($fittingId);
        $this->info("=== With eager loading ===");
        $this->line("Project: " . ($fitting2->project ? 'loaded' : 'null'));
        if ($fitting2->project) {
            $this->line("  ID: {$fitting2->project->id}");
            $this->line("  User ID: {$fitting2->project->user_id}");
        }
    }
}
