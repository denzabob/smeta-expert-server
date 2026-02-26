<?php

namespace App\Console\Commands;

use App\Models\ProjectLaborWork;
use Illuminate\Console\Command;

class CheckLaborWorks extends Command
{
    protected $signature = 'check:labor-works {projectId=4}';
    protected $description = 'Check labor works in a project';

    public function handle()
    {
        $projectId = $this->argument('projectId');
        
        $works = ProjectLaborWork::where('project_id', $projectId)
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'title', 'position_profile_id', 'rate_per_hour', 'cost_total']);

        $this->info('Last 10 labor works in project ' . $projectId . ':');
        $this->line('');
        
        foreach ($works as $work) {
            $profile = $work->position_profile_id ?? 'NULL';
            $rate = $work->rate_per_hour ?? 'NULL';
            $cost = $work->cost_total ?? 'NULL';
            $this->line(sprintf(
                "ID: %d | %s | Profile: %s | Rate: %s | Cost: %s",
                $work->id,
                $work->title,
                $profile,
                $rate,
                $cost
            ));
        }
    }
}
