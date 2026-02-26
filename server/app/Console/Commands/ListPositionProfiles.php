<?php

namespace App\Console\Commands;

use App\Models\PositionProfile;
use Illuminate\Console\Command;

class ListPositionProfiles extends Command
{
    protected $signature = 'list:position-profiles';
    protected $description = 'List all position profiles';

    public function handle()
    {
        $profiles = PositionProfile::all(['id', 'name']);

        if ($profiles->isEmpty()) {
            $this->warning('No position profiles found');
            return;
        }

        $this->info('Available Position Profiles:');
        $this->line('');
        
        foreach ($profiles as $profile) {
            $this->line("ID: {$profile->id} | {$profile->name}");
        }
    }
}
