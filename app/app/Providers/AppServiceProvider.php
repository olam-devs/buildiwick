<?php

namespace App\Providers;

use App\Models\EstimateEntry;
use App\Models\InternalBudget;
use App\Models\InternalBudgetEntry;
use App\Models\LabourAgreement;
use App\Models\Lookup;
use App\Models\Project;
use App\Models\ProjectEstimate;
use App\Models\ProjectPhase;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Applies before every policy check: a deactivated account can do
        // nothing at all, and Admin is a genuine bypass — never gated per
        // ability, matching "Admin/Directors: everything" from the brief.
        Gate::before(function (User $user, string $ability, array $arguments = []) {
            if (! $user->is_active) {
                return false;
            }

            if ($user->hasRole('admin')) {
                $target = $arguments[0] ?? null;
                if ($ability === 'delete' && ($target instanceof Project || $target instanceof ProjectPhase || in_array($target, [Project::class, ProjectPhase::class], true))) {
                    return null; // These policies enforce historical integrity even for Admin.
                }
                if (in_array($ability, ['update', 'delete', 'finalize'], true)
                    && ($target instanceof ProjectEstimate || $target instanceof EstimateEntry
                        || in_array($target, [ProjectEstimate::class, EstimateEntry::class], true))) {
                    return null; // Estimate lifecycle and invoice-reference locks apply to Admin too.
                }
                if (in_array($ability, ['update', 'delete', 'finalize'], true)
                    && ($target instanceof InternalBudget || $target instanceof InternalBudgetEntry
                        || in_array($target, [InternalBudget::class, InternalBudgetEntry::class], true))) {
                    return null; // Budget baseline locks apply to Admin too.
                }

                return true;
            }

            return null; // fall through to the specific policy method
        });

        // No Eloquent model behind this one — it's the rollup/reporting screens,
        // not a single record. Estimator/QS and Site Operator never get it:
        // company cash position is exactly what the brief keeps from both roles.
        Gate::define('view-dashboard', fn (User $user) => in_array(
            $user->role?->name, ['admin', 'accounts', 'project_manager'], true
        ));

        Gate::define('view-director-analytics', fn (User $user) => in_array(
            $user->role?->name, ['admin', 'director'], true
        ));

        Gate::define('manage-users', fn (User $user) => $user->hasRole('admin'));

        // Feeds the project-level "New Entry" drawer.
        View::composer('partials.new-entry-drawer', function ($view) {
            $user = Auth::user();
            if (! $user) {
                return;
            }

            $projectIds = $user->hasRole('project_manager') || $user->hasRole('site_operator')
                ? $user->assignedProjects()->pluck('projects.id')
                : Project::pluck('id');

            $view->with([
                'drawerProjects' => Project::whereIn('id', $projectIds)->orderBy('name')->get(['id', 'name']),
                'drawerLookups' => Lookup::where('is_active', true)->orderBy('sort_order')->get()->groupBy('type'),
                'drawerAgreements' => LabourAgreement::whereIn('project_id', $projectIds)->get(['id', 'project_id', 'fundi_subcontractor', 'scope_of_work']),
            ]);
        });
    }
}
