<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * The one place a transaction row gets created, whichever door it came through
 * (the JSON API or the web entry form) — so the labour-instalment-is-already-paid
 * rule and the offline client_uuid idempotency can't drift between the two.
 */
class TransactionRecorder
{
    public function record(array $data, Project $project, User $enteredBy, ?UploadedFile $evidence = null): array
    {
        if (! empty($data['client_uuid'])) {
            $existing = Transaction::where('client_uuid', $data['client_uuid'])->first();
            if ($existing) {
                return [$existing, true];
            }
        }

        $evidencePath = $evidence?->store('evidence', 'public');

        // A labour/subcontract instalment IS the payment event — Finance logs it
        // only once the money has actually moved. Every other category is a
        // cost incurred now, paid later by Accounts' separate recordPayment.
        $isLabourInstalment = $data['main_category'] === 'labour_subcontracts';

        $transaction = Transaction::create(
            collect($data)->except(['evidence', 'client_uuid'])->toArray() + [
                'client_uuid' => $data['client_uuid'] ?? (string) Str::uuid(),
                'transaction_code' => $project->project_code.'-TXN-'.str_pad((string) ($project->transactions()->count() + 1), 4, '0', STR_PAD_LEFT),
                'transaction_type' => 'expense',
                'amount_paid_received' => $isLabourInstalment ? $data['total_amount'] : 0,
                'payment_status' => $isLabourInstalment ? 'paid' : 'to_confirm',
                'data_status' => 'needs_confirmation',
                'evidence_link' => $evidencePath,
                'entered_by' => $enteredBy->id,
                'synced_at' => now(),
            ]
        );

        return [$transaction, false];
    }
}
