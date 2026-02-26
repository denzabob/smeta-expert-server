<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\ProjectLaborWork;
use App\Models\ProjectLaborWorkStep;
use App\Observers\ProjectLaborWorkObserver;
use App\Observers\ProjectLaborWorkStepObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ProjectLaborWork::observe(ProjectLaborWorkObserver::class);
        ProjectLaborWorkStep::observe(ProjectLaborWorkStepObserver::class);
    }
}
