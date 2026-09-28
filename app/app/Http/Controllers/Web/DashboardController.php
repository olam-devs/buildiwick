<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\LabourAgreement;
use App\Models\Project;
use App\Models\Transaction;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    private const CATEGORIES = ['materials', 'labour_subcontracts', 'logistics_equipment', 'preliminaries_overheads'];

    public function index(Request $request)
    {
        $this->authorize('view-dashboard');

        $projectIds = $this->visibleProjectIds($request);
        $projects = Project::whereIn('id', $projectIds)->orderBy('name')->get();

        $categoryRows = Transaction::query()
            ->whereIn('project_id', $projectIds)
            ->selectRaw('project_id, main_category, SUM(total_amount) as total, SUM(amount_paid_received) as paid')
            ->groupBy('project_id', 'main_category')
            ->get()
            ->groupBy('project_id');

        $agreementTotals = LabourAgreement::whereIn('project_id', $projectIds)
            ->selectRaw('project_id, SUM(agreed_value) as agreed_value')
            ->groupBy('project_id')
            ->pluck('agreed_value', 'project_id');

        $rows = $projects->map(function (Project $project) use ($categoryRows, $agreementTotals) {
            $byCategory = $categoryRows->get($project->id, collect())->keyBy('main_category');

            $categories = [];
            $total = 0;
            foreach (self::CATEGORIES as $cat) {
                $categories[$cat] = (float) ($byCategory[$cat]->total ?? 0);
                $total += $categories[$cat];
            }
            $paid = (float) $byCategory->sum('paid');
            $agreementsValue = (float) ($agreementTotals[$project->id] ?? 0);

            return (object) [
                'project' => $project,
                'categories' => $categories,
                'total' => $total,
                'paid' => $paid,
                'unpaid' => $total - $paid,
                'labour_agreements' => $agreementsValue,
                'labour_outstanding' => $agreementsValue - $categories['labour_subcontracts'],
                'has_activity' => $total > 0,
            ];
        });

        $summary = (object) [
            'active_projects' => $rows->count(),
            'total' => $rows->sum('total'),
            'paid' => $rows->sum('paid'),
            'unpaid' => $rows->sum('unpaid'),
        ];

        return view('dashboard.index', [
            'rows' => $rows,
            'summary' => $summary,
        ]);
    }

    public function show(Request $request, Project $project)
    {
        $this->authorize('view-dashboard');
        abort_unless(in_array($project->id, $this->visibleProjectIds($request), true), 403);

        $totals = $project->categoryTotals();

        $agreements = $project->labourAgreements()->get()->map(fn (LabourAgreement $a) => (object) [
            'agreement' => $a,
            'paid_to_date' => $a->paidToDate(),
            'outstanding' => $a->outstanding(),
        ]);

        $needsAttention = $project->transactions()
            ->whereIn('data_status', ['needs_confirmation', 'pending_approval'])
            ->orderByDesc('transaction_date')
            ->get();

        return view('dashboard.show', [
            'project' => $project,
            'categories' => $totals['categories'],
            'total' => $totals['total_expenses'],
            'paid' => $totals['cash_paid_out'],
            'unpaid' => $totals['unpaid_expenses'],
            'agreements' => $agreements,
            'needsAttention' => $needsAttention,
        ]);
    }

    private function visibleProjectIds(Request $request): array
    {
        $user = $request->user();

        if ($user->hasRole('project_manager')) {
            return $user->assignedProjects()->pluck('projects.id')->all();
        }

        return Project::pluck('id')->all();
    }
}
