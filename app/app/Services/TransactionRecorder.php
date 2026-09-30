<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one place a transaction row gets created, whichever door it came through
 * (the JSON API or the web entry form) — so the labour-instalment-is-already-paid
 * rule and the offline client_uuid idempotency can't drift between the two.
 */
class TransactionRecorder
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function record(array $data, Project $project, User $enteredBy, ?UploadedFile $evidence = null): array
    {
        $evidencePath = null;

        try {
            return $this->database->transaction(function () use ($data, $project, $enteredBy, $evidence, &$evidencePath): array {
                $lockedProject = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($enteredBy)->authorize('create', [Transaction::class, $lockedProject]);

                if (! empty($data['client_uuid'])) {
                    $existing = Transaction::where('client_uuid', $data['client_uuid'])->first();
                    if ($existing) {
                        return [$existing, true];
                    }
                }

                $evidencePath = $evidence?->store('evidence', 'local');

                // A legacy labour instalment is a payment event. Other costs
                // remain payable entries for the separate Accounts workflow.
                $isLabourInstalment = $data['main_category'] === 'labour_subcontracts';
                $transaction = Transaction::create(
                    collect($data)->except(['evidence', 'client_uuid'])->toArray() + [
                        'client_uuid' => $data['client_uuid'] ?? (string) Str::uuid(),
                        'transaction_code' => $lockedProject->project_code.'-TXN-'.str_pad((string) ($lockedProject->transactions()->count() + 1), 4, '0', STR_PAD_LEFT),
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
            });
        } catch (Throwable $exception) {
            if ($evidencePath) {
                Storage::disk('local')->delete($evidencePath);
            }

            throw $exception;
        }
    }
}
