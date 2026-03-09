<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\MaterialDimensionParseFailure;
use App\Models\MaterialDimensionRule;
use App\Models\MaterialTypePattern;
use App\Models\Idea;
use App\Models\ProjectLaborWork;
use App\Models\ProjectLaborWorkStep;
use App\Observers\ProjectLaborWorkObserver;
use App\Observers\ProjectLaborWorkStepObserver;
use App\Policies\IdeaPolicy;
use App\Policies\MaterialDimensionParseFailurePolicy;
use App\Policies\MaterialDimensionRulePolicy;
use App\Policies\MaterialTypePatternPolicy;

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
        Gate::policy(MaterialDimensionRule::class, MaterialDimensionRulePolicy::class);
        Gate::policy(MaterialDimensionParseFailure::class, MaterialDimensionParseFailurePolicy::class);
        Gate::policy(MaterialTypePattern::class, MaterialTypePatternPolicy::class);
        Gate::policy(Idea::class, IdeaPolicy::class);

        ProjectLaborWork::observe(ProjectLaborWorkObserver::class);
        ProjectLaborWorkStep::observe(ProjectLaborWorkStepObserver::class);
    }
}
