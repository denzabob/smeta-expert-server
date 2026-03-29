<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Relations\Relation;
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
        Relation::morphMap([
            'project_position'  => \App\Models\ProjectPosition::class,
            'project_fitting'   => \App\Models\ProjectFitting::class,
            'operation'         => \App\Models\Operation::class,
            'project_labor_work' => \App\Models\ProjectLaborWork::class,
            'expense'           => \App\Models\Expense::class,
            'evidence_record'   => \App\Models\EvidenceRecord::class,
            'material_price_history' => \App\Models\MaterialPriceHistory::class,
        ]);

        Gate::policy(MaterialDimensionRule::class, MaterialDimensionRulePolicy::class);
        Gate::policy(MaterialDimensionParseFailure::class, MaterialDimensionParseFailurePolicy::class);
        Gate::policy(MaterialTypePattern::class, MaterialTypePatternPolicy::class);
        Gate::policy(Idea::class, IdeaPolicy::class);

        ProjectLaborWork::observe(ProjectLaborWorkObserver::class);
        ProjectLaborWorkStep::observe(ProjectLaborWorkStepObserver::class);
    }
}
