<?php

namespace App\Console\Commands;

use App\Models\ProjectFitting;
use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

class TestFittingUpdateApi extends Command
{
    protected $signature = 'test:fitting-update-api {fitting_id} {user_id}';
    protected $description = 'Test fitting update via API logic';

    public function handle()
    {
        $fittingId = $this->argument('fitting_id');
        $userId = $this->argument('user_id');
        
        // Simulate authenticated user
        $user = \App\Models\User::findOrFail($userId);
        Auth::setUser($user);
        
        $fitting = ProjectFitting::find($fittingId);
        if (!$fitting) {
            $this->error("Fitting #{$fittingId} not found");
            return;
        }
        
        $this->info("=== Testing Nested Route: projects.fittings.update ===");
        $this->line("User: {$user->name} (ID: {$user->id})");
        $this->line("Fitting: {$fitting->name} (ID: {$fitting->id})");
        $this->line("Project ID: {$fitting->project_id}");
        
        // Nested route: projects/{project}/fittings/{fitting}
        $project = Project::findOrFail($fitting->project_id);
        $this->line("Project: {$project->number} (Owner: {$project->user_id})");
        
        if ($fitting->project_id !== $project->id) {
            $this->error("✗ Fitting doesn't belong to project");
            return;
        }
        
        try {
            $this->authorize('update', $project);
            $this->line("✓ Authorization passed");
        } catch (\Exception $e) {
            $this->error("✗ Authorization failed: " . $e->getMessage());
            return;
        }
        
        // Try to update
        $newName = $fitting->name . ' (API test)';
        $fitting->update(['name' => $newName]);
        $this->info("✓ Updated successfully: {$fitting->name}");
        
        // Revert
        $fitting->update(['name' => str_replace(' (API test)', '', $fitting->name)]);
        $this->line("✓ Reverted");
        
        // Test top-level route
        $this->line("");
        $this->info("=== Testing Top-Level Route: project-fittings.update ===");
        
        $fitting2 = ProjectFitting::with('project')->findOrFail($fittingId);
        $project2 = $fitting2->project;
        
        if (!$project2) {
            $this->error("✗ Project not loaded via relation");
            return;
        }
        
        $this->line("Project loaded: {$project2->number} (User: {$project2->user_id})");
        
        try {
            $this->authorize('update', $project2);
            $this->line("✓ Authorization passed");
        } catch (\Exception $e) {
            $this->error("✗ Authorization failed: " . $e->getMessage());
            return;
        }
    }
    
    protected function authorize($action, $model)
    {
        $policy = Auth::user()->can($action, $model);
        if (!$policy) {
            throw new \Exception("Unauthorized");
        }
    }
}
