<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransactionRequest;
use App\Models\Project;
use App\Models\Transaction;
use App\Services\TransactionRecorder;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Transaction::class);

        $user = $request->user();
        $query = Transaction::query()->with('project:id,name,project_code');

        if ($user->hasRole('project_manager')) {
            $query->whereIn('project_id', $user->assignedProjects()->pluck('projects.id'));
        }

        if ($user->hasRole('estimator_qs')) {
            // Historical materials pricing only — never cash-movement fields.
            return $query->where('main_category', 'materials')->get([
                'id', 'project_id', 'main_category', 'subcategory', 'item_description',
                'quantity', 'unit', 'unit_cost', 'transaction_date',
            ]);
        }

        return $query->latest('transaction_date')->get();
    }

    /**
     * The entry form's one write path — Materials, Logistics & Equipment,
     * Preliminaries & Overheads all post here with a bare main_category;
     * a Labour & Subcontracts instalment posts here too, with agreement_id set.
     * Offline-safe: retrying the same client_uuid returns the original row,
     * never creates a second one.
     */
    public function store(StoreTransactionRequest $request, TransactionRecorder $recorder)
    {
        $data = $request->validated();

        $project = Project::findOrFail($data['project_id']);
        $this->authorize('create', [Transaction::class, $project]);

        [$transaction, $alreadySynced] = $recorder->record(
            $data, $project, $request->user(), $request->file('evidence')
        );

        return $this->respond($request, $transaction->fresh('project'), $alreadySynced);
    }

    public function approve(Request $request, Transaction $transaction)
    {
        $this->authorize('approve', $transaction);

        $transaction->update([
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'data_status' => 'approved',
        ]);

        return response()->json($transaction->fresh());
    }

    public function recordPayment(Request $request, Transaction $transaction)
    {
        $this->authorize('recordPayment', $transaction);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', 'string'],
        ]);

        $paid = min(
            (float) $transaction->total_amount,
            (float) $transaction->amount_paid_received + $data['amount']
        );

        // Payment Status is derived from the numbers, never set by hand —
        // the direct fix for the "trusted but wrong" bug the client hit before.
        $status = match (true) {
            $paid >= (float) $transaction->total_amount => 'paid',
            $paid > 0 => 'part_paid',
            default => 'unpaid',
        };

        $transaction->update([
            'amount_paid_received' => $paid,
            'payment_method' => $data['payment_method'],
            'payment_status' => $status,
        ]);

        return response()->json($transaction->fresh());
    }

    /**
     * Site Operator's whole security model lives here: echo back only the
     * fields just submitted, never the saved row, never anyone else's data —
     * whether this is a brand-new entry or a replayed offline sync.
     */
    private function respond(Request $request, Transaction $transaction, bool $alreadySynced)
    {
        if ($request->user()->hasRole('site_operator')) {
            return response()->json([
                'synced' => true,
                'already_recorded' => $alreadySynced,
            ] + $request->only([
                'main_category', 'item_description', 'quantity', 'unit', 'unit_cost', 'total_amount',
            ]));
        }

        return response()->json($transaction, $alreadySynced ? 200 : 201);
    }
}
