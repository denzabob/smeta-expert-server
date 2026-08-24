<?php

namespace App\Providers;

use App\Domain\PriceIndices\Application\Contracts\ClassifierHttpTransport;
use App\Domain\PriceIndices\Infrastructure\Http\LaravelClassifierHttpTransport;
use App\Models\Chat\ChatConversation;
use App\Models\Idea;
use App\Models\MaterialDimensionParseFailure;
use App\Models\MaterialDimensionRule;
use App\Models\MaterialTypePattern;
use App\Models\ProjectLaborWork;
use App\Models\ProjectLaborWorkStep;
use App\Observers\ProjectLaborWorkObserver;
use App\Observers\ProjectLaborWorkStepObserver;
use App\Policies\ChatConversationPolicy;
use App\Policies\IdeaPolicy;
use App\Policies\MaterialDimensionParseFailurePolicy;
use App\Policies\MaterialDimensionRulePolicy;
use App\Policies\MaterialTypePatternPolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ClassifierHttpTransport::class, LaravelClassifierHttpTransport::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::morphMap([
            'material' => \App\Models\Material::class,
            'project_position' => \App\Models\ProjectPosition::class,
            'project_fitting' => \App\Models\ProjectFitting::class,
            'operation' => \App\Models\Operation::class,
            'labor' => \App\Models\ProjectProfileRate::class,
            'product' => \App\Models\Material::class,
            'project_labor_work' => \App\Models\ProjectLaborWork::class,
            'expense' => \App\Models\Expense::class,
            'evidence_record' => \App\Models\EvidenceRecord::class,
            'material_price_history' => \App\Models\MaterialPriceHistory::class,
            'operation_price' => \App\Models\OperationPrice::class,
            'price_list_version' => \App\Models\PriceListVersion::class,
        ]);

        Gate::policy(MaterialDimensionRule::class, MaterialDimensionRulePolicy::class);
        Gate::policy(MaterialDimensionParseFailure::class, MaterialDimensionParseFailurePolicy::class);
        Gate::policy(MaterialTypePattern::class, MaterialTypePatternPolicy::class);
        Gate::policy(Idea::class, IdeaPolicy::class);
        Gate::policy(ChatConversation::class, ChatConversationPolicy::class);

        ProjectLaborWork::observe(ProjectLaborWorkObserver::class);
        ProjectLaborWorkStep::observe(ProjectLaborWorkStepObserver::class);
    }
}
