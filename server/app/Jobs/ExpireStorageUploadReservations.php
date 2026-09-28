<?php

namespace App\Jobs;

use App\Services\Storage\StorageUsageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExpireStorageUploadReservations implements ShouldQueue
{
    use Queueable;

    public function handle(StorageUsageService $usage): void
    {
        $usage->expireReservations();
    }
}
