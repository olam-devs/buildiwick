<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LabourAgreement;
use App\Models\Project;
use App\Models\Transaction;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    private const CATEGORIES = ['materials', 'labour_subcontracts', 'logistics_equipment', 'preliminaries_overheads'];

    /**
     * GET /dashboard — the All Projects landing view: the same rollup the old
     * Consolidated tab held by hand, computed live from `transactions` and
     * `labour_agreements` instead. Project Manager gets the same shape,
     * scoped to their own assigned projects — one query, two audiences.
     */
    public function index(Request $request)
    {
        $this->authorize('view-dashboard');

        $projectIds = $this->visibleProjectIds($request);

        $projects = Project::whereIn('id', $projectIds)->orderBy('name')->get(['id', 'project_code', 'name', 'status']);

        // One grouped query for every project's category totals, instead of N+1 per-project queries.
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
            $totalExpenses = 0;
            foreach (self::CATEGORIES as $cat) {
                $categories[$cat] = (float) ($byCategory[$cat]->total ?? 0);
                $totalExpenses += $categories[$cat];
            }

            $cashPaidOut = (float) $byCategory->sum('paid');
            $labourAgreementsValue = (float) ($agreementTotals[$project->id] ?? 0);
            $labourPaid = $categories['labour_subcontracts']; // an instalment IS the payment, see TransactionController

            return [
                'id' => $project->id,
                'project_code' => $project->project_code,
                'name' => $project->name,
                'status' => $project->status,
                'categories' => $categories,
                'total_expenses' => $totalExpenses,
                'cash_paid_out' => $cashPaidOut,
                'unpaid_expenses' => $totalExpenses - $cashPaidOut,
                'labour_agreements' => $labourAgreementsValue,
                'labour_paid' => $labourPaid,
                'labour_outstanding' => $labourAgreementsValue - $labourPaid,
            ];
        });

        $summary = [
            'active_projects' => $rows->count(),
            'total_expenses' => $rows->sum('total_expenses'),
            'cash_paid_out' => $rows->sum('cash_paid_out'),
            'unpaid_expenses' => $rows->sum('unpaid_expenses'),
        ];

        return response()->json(['summary' => $summary, 'projects' => $rows->values()]);
    }

    /**
     * GET /dashboard/projects/{project} — the drill-down: same numbers, one
     * level deeper, plus the labour agreements and what still needs a look.
     */
    public function show(Request $request, Project $project)
    {
        $this->authorize('view-dashboard');
        $this->assertVisible($request, $project);

        $totals = $project->categoryTotals();

        $agreements = $project->labourAgreements()->get()->map(fn (LabourAgreement $a) => [
            'id' => $a->id,
            'agreement_code' => $a->agreement_code,
            'fundi_subcontractor' => $a->fundi_subcontractor,
            'scope_of_work' => $a->scope_of_work,
            'agreed_value' => (float) $a->agreed_value,
            'paid_to_date' => $a->paidToDate(),
            'outstanding' => $a->outstanding(),
            'status' => $a->status,
        ]);

        $needsAttention = $project->transactions()
            ->whereIn('data_status', ['needs_confirmation', 'pending_approval'])
            ->orderByDesc('transaction_date')
            ->get(['id', 'transaction_code', 'main_category', 'item_description', 'total_amount', 'data_status', 'entered_by'])
            ->map(fn (Transaction $t) => [
                'id' => $t->id,
                'transaction_code' => $t->transaction_code,
                'main_category' => $t->main_category,
                'item_description' => $t->item_description,
                'total_amount' => (float) $t->total_amount,
                'data_status' => $t->data_status,
            ]);

        return response()->json([
            'project' => $project->only(['id', 'project_code', 'name', 'status', 'location']),
            'categories' => $totals['categories'],
            'total_expenses' => $totals['total_expenses'],
            'cash_paid_out' => $totals['cash_paid_out'],
            'unpaid_expenses' => $totals['unpaid_expenses'],
            'labour_agreements' => $agreements,
            'needs_attention' => $needsAttention,
        ]);
    }

    /**
     * GET /dashboard/bills — Accounts' queue: every expense line not yet fully
     * paid, across every project (Accounts isn't project-scoped in the brief).
     */
    public function bills(Request $request)
    {
        $this->authorize('view-dashboard');
        abort_unless(in_array($request->user()->role?->name, ['admin', 'accounts'], true), 403);

        $bills = Transaction::with('project:id,name,project_code')
            ->whereIn('payment_status', ['to_confirm', 'unpaid', 'part_paid'])
            ->orderByDesc('transaction_date')
            ->get()
            ->map(fn (Transaction $t) => [
                'id' => $t->id,
                'project' => $t->project->name,
                'main_category' => $t->main_category,
                'item_description' => $t->item_description,
                'supplier_fundi_client' => $t->supplier_fundi_client,
                'total_amount' => (float) $t->total_amount,
                'amount_paid_received' => (float) $t->amount_paid_received,
                'balance' => $t->balance,
                'payment_status' => $t->payment_status,
            ]);

        return response()->json([
            'count' => $bills->count(),
            'total_outstanding' => $bills->sum('balance'),
            'bills' => $bills,
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

    private function assertVisible(Request $request, Project $project): void
    {
        abort_unless(in_array($project->id, $this->visibleProjectIds($request), true), 403);
    }
}
