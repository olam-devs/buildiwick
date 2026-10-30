<?php

namespace App\Services;

use App\Models\ExpensePayment;
use App\Models\ExpenseRequest;
use App\Models\Lookup;
use App\Models\Project;
use App\Models\ProjectEditLog;
use App\Models\ProjectPhase;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Recording-mode expense ledger: one line per expense, exactly like the client's tracker.
 *
 * A line is stored as an approved ExpenseRequest (approval stage "recorded", no approval step) so every
 * existing report, payable and warning reads it. "To confirm" lines have no payment yet; the Accountant
 * (or Director) confirms them by recording the amount paid through ExpensePaymentRecorder. The PM logs
 * lines and never marks them paid. Codes follow the tracker: OYS-RES-TXN-0001.
 */
class ExpenseLedgerService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ExpensePaymentRecorder $payments,
    ) {}

    public static function codePrefix(Project $project): string
    {
        return preg_match('/^BWG-(.+)-\d{4}-\d{3}$/', (string) $project->project_code, $match) ? $match[1] : (string) $project->project_code;
    }

    public static function canMarkPaid(User $user): bool
    {
        return $user->permits('mark_paid');
    }

    /**
     * @param  array{expense_date: string, project_phase_id: int, main_category: string, subcategory?: ?string, item_description: string, details?: ?string, quantity?: mixed, unit?: ?string, unit_cost?: mixed, total_amount?: mixed, invoice_receipt_no?: ?string, labour_agreement_id?: ?int, status?: string, payment_date?: ?string, payment_method?: ?string, transaction_reference?: ?string}  $data
     */
    public function record(Project $project, array $data, User $actor): ExpenseRequest
    {
        Gate::forUser($actor)->authorize('record-expense', $project);
        $markPaid = ($data['status'] ?? 'to_confirm') === 'paid';
        if ($markPaid && ! self::canMarkPaid($actor)) {
            throw ValidationException::withMessages(['status' => 'Only Accounts or the Director can record a line as paid. Save it as "To confirm".']);
        }
        [$quantity, $unitCost, $total] = $this->amounts($data);
        // The Director's own entries need no confirmation: a line with an amount is recorded as paid.
        if ($actor->hasRole('admin')) {
            $markPaid = Money::compare($total, '0') > 0;
            $data['payment_method'] = ($data['payment_method'] ?? null) ?: 'other';
        }
        if ($markPaid && Money::compare($total, '0') <= 0) {
            throw ValidationException::withMessages(['total_amount' => 'Enter the amount before recording the line as paid.']);
        }

        return $this->database->transaction(function () use ($project, $data, $actor, $markPaid, $quantity, $unitCost, $total): ExpenseRequest {
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $phase = $locked->phases()->whereKey($data['project_phase_id'])->first();
            if (! $phase) {
                throw ValidationException::withMessages(['project_phase_id' => 'Choose a phase of this project.']);
            }
            $this->assertLowestLevel($phase);
            $date = CarbonImmutable::parse($data['expense_date'])->toDateString();
            $request = ExpenseRequest::create([
                'request_code' => $this->nextCode($locked),
                'project_id' => $locked->id,
                'project_phase_id' => $phase->id,
                'labour_agreement_id' => $data['labour_agreement_id'] ?? null,
                'request_type' => ($data['main_category'] === 'labour_subcontracts') ? 'labour' : 'item',
                'procurement_stage' => 'approved',
                'main_category' => $data['main_category'],
                'subcategory' => $data['subcategory'] ?? null,
                'item_description' => trim($data['item_description']),
                'invoice_receipt_no' => $data['invoice_receipt_no'] ?? null,
                'quantity' => $quantity,
                'unit' => $data['unit'] ?? null,
                'unit_cost' => $unitCost,
                'total_amount' => $total,
                'commercial_unit_rate' => $unitCost,
                'commercial_total' => $total,
                'commercial_payment_method' => $data['payment_method'] ?? null,
                'approved_amount' => $total,
                'requested_at' => $date,
                'submitted_at' => now(),
                'status' => 'approved',
                'requested_by' => $actor->id,
                'notes' => $data['details'] ?? null,
            ]);
            $request->approvals()->create([
                'approval_stage' => 'recorded',
                'decision' => 'approved',
                'approved_amount' => $total,
                'approved_unit_rate' => $unitCost,
                'approved_total' => $total,
                'payment_method_snapshot' => $data['payment_method'] ?? null,
                'decided_by' => $actor->id,
                'decided_at' => CarbonImmutable::parse($date)->startOfDay(),
                'notes' => 'Recorded directly (recording mode).',
            ]);
            if ($markPaid) {
                $this->payments->record($request->id, [
                    'amount' => $total,
                    'payment_date' => $data['payment_date'] ?? $date,
                    'payment_method' => ($data['payment_method'] ?? null) ?: 'other',
                    'invoice_receipt_no' => $data['invoice_receipt_no'] ?? null,
                    'transaction_reference' => $data['transaction_reference'] ?? null,
                ], $actor);
            }

            $this->rememberMaterial($request->item_description, $request->main_category);

            return $request->fresh(['payments', 'phase']);
        });
    }

    /** Edits a line that has no payment yet. */
    public function update(ExpenseRequest $line, array $data, User $actor): ExpenseRequest
    {
        Gate::forUser($actor)->authorize('edit-ledger-line', $line);
        [$quantity, $unitCost, $total] = $this->amounts($data);

        return $this->database->transaction(function () use ($line, $data, $actor, $quantity, $unitCost, $total): ExpenseRequest {
            $locked = ExpenseRequest::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            $payments = $locked->payments()->orderBy('id')->get();
            if ($payments->isNotEmpty() && ! $actor->hasRole('admin')) {
                throw ValidationException::withMessages(['line' => 'This line already has a payment recorded and can no longer be edited.']);
            }
            $phase = $locked->project->phases()->whereKey($data['project_phase_id'])->first();
            if (! $phase) {
                throw ValidationException::withMessages(['project_phase_id' => 'Choose a phase of this project.']);
            }
            // A line already sitting on a phase that later got subphases may stay there; moving it needs the lowest level.
            if ((int) $phase->id !== (int) $locked->project_phase_id) {
                $this->assertLowestLevel($phase);
            }
            $before = $locked->only(['project_phase_id', 'main_category', 'subcategory', 'item_description', 'quantity', 'unit', 'unit_cost', 'total_amount', 'requested_at']);
            $locked->update([
                'project_phase_id' => $data['project_phase_id'],
                'labour_agreement_id' => $data['labour_agreement_id'] ?? null,
                'main_category' => $data['main_category'],
                'request_type' => ($data['main_category'] === 'labour_subcontracts') ? 'labour' : 'item',
                'subcategory' => $data['subcategory'] ?? null,
                'item_description' => trim($data['item_description']),
                'invoice_receipt_no' => $data['invoice_receipt_no'] ?? null,
                'quantity' => $quantity,
                'unit' => $data['unit'] ?? null,
                'unit_cost' => $unitCost,
                'total_amount' => $total,
                'commercial_unit_rate' => $unitCost,
                'commercial_total' => $total,
                'approved_amount' => $total,
                'requested_at' => CarbonImmutable::parse($data['expense_date'])->toDateString(),
                'notes' => $data['details'] ?? null,
            ]);
            $locked->approvals()->where('approval_stage', 'recorded')->update(['approved_amount' => $total, 'approved_total' => $total, 'approved_unit_rate' => $unitCost]);
            $this->log($locked, 'ledger_line_updated', $actor, $before, $locked->only(array_keys($before)));
            if ($payments->isNotEmpty()) {
                $this->repay($locked, $payments, (string) $before['total_amount'], $actor);
            }

            return $locked->fresh(['payments', 'phase']);
        });
    }

    /**
     * Accounts confirms a line: optionally corrects the amount, then records what was paid.
     *
     * @param  array{total_amount?: mixed, amount: mixed, payment_date: string, payment_method: string, transaction_reference?: ?string, invoice_receipt_no?: ?string}  $data
     */
    public function confirm(ExpenseRequest $line, array $data, User $actor): ExpenseRequest
    {
        if (! self::canMarkPaid($actor)) {
            throw ValidationException::withMessages(['line' => 'Only Accounts or the Director can confirm payments.']);
        }
        Gate::forUser($actor)->authorize('confirm-payments', $line->project);

        // Method and receipt number are optional; a payment with no method given is stored as "Other".
        $data['payment_method'] = ($data['payment_method'] ?? null) ?: 'other';

        return $this->database->transaction(function () use ($line, $data, $actor): ExpenseRequest {
            $locked = ExpenseRequest::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            if (isset($data['total_amount']) && $data['total_amount'] !== '' && $locked->payments()->doesntExist()) {
                $total = Money::decimal($data['total_amount'])->toScale(2)->__toString();
                if (Money::compare($total, $locked->total_amount) !== 0) {
                    $before = ['total_amount' => (string) $locked->total_amount];
                    $unitCost = $locked->quantity && (float) $locked->quantity > 0 ? number_format((float) $total / (float) $locked->quantity, 2, '.', '') : $locked->unit_cost;
                    $locked->update(['total_amount' => $total, 'commercial_total' => $total, 'approved_amount' => $total, 'unit_cost' => $unitCost, 'commercial_unit_rate' => $unitCost]);
                    $locked->approvals()->where('approval_stage', 'recorded')->update(['approved_amount' => $total, 'approved_total' => $total]);
                    $this->log($locked, 'ledger_amount_confirmed', $actor, $before, ['total_amount' => $total]);
                }
            }
            $locked->approvals()->where('approval_stage', 'recorded')->update(['payment_method_snapshot' => $data['payment_method']]);
            $locked->update(['commercial_payment_method' => $data['payment_method']]);
            $this->payments->record($locked->id, [
                'amount' => $data['amount'],
                'payment_date' => $data['payment_date'],
                'payment_method' => $data['payment_method'],
                'transaction_reference' => $data['transaction_reference'] ?? null,
                'invoice_receipt_no' => ($data['invoice_receipt_no'] ?? null) ?: $locked->invoice_receipt_no,
            ], $actor);

            return $locked->fresh(['payments', 'phase']);
        });
    }

    /** Accounts or the Director reject a line that should not count; it leaves every total but stays visible as Denied. */
    public function deny(ExpenseRequest $line, string $reason, User $actor): ExpenseRequest
    {
        Gate::forUser($actor)->authorize('deny-expense', $line->project);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Say why the expense is denied.']);
        }

        return $this->database->transaction(function () use ($line, $reason, $actor): ExpenseRequest {
            $locked = ExpenseRequest::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'approved') {
                throw ValidationException::withMessages(['line' => 'This line is already denied.']);
            }
            if ($locked->payments()->exists()) {
                throw ValidationException::withMessages(['line' => 'A line with a recorded payment cannot be denied.']);
            }
            $locked->update(['status' => 'rejected']);
            $locked->approvals()->create(['approval_stage' => 'recorded', 'decision' => 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'notes' => $reason]);
            $this->log($locked, 'ledger_line_denied', $actor, ['status' => 'approved'], ['status' => 'rejected', 'reason' => $reason]);

            return $locked->fresh();
        });
    }

    public function restore(ExpenseRequest $line, User $actor): ExpenseRequest
    {
        Gate::forUser($actor)->authorize('deny-expense', $line->project);

        return $this->database->transaction(function () use ($line, $actor): ExpenseRequest {
            $locked = ExpenseRequest::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'rejected') {
                throw ValidationException::withMessages(['line' => 'Only a denied line can be restored.']);
            }
            $locked->update(['status' => 'approved']);
            $locked->approvals()->create(['approval_stage' => 'recorded', 'decision' => 'approved', 'approved_amount' => $locked->total_amount,
                'approved_total' => $locked->total_amount, 'decided_by' => $actor->id, 'decided_at' => now(), 'notes' => 'Restored after being denied.']);
            $this->log($locked, 'ledger_line_restored', $actor, ['status' => 'rejected'], ['status' => 'approved']);

            return $locked->fresh();
        });
    }

    public function delete(ExpenseRequest $line, User $actor): void
    {
        Gate::forUser($actor)->authorize('edit-ledger-line', $line);
        $this->database->transaction(function () use ($line, $actor): void {
            $locked = ExpenseRequest::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            $payments = $locked->payments()->get();
            if ($payments->isNotEmpty() && ! $actor->hasRole('admin')) {
                throw ValidationException::withMessages(['line' => 'This line already has a payment recorded and cannot be deleted.']);
            }
            $this->log($locked, 'ledger_line_deleted', $actor, $locked->only(['request_code', 'item_description', 'total_amount']) + ['paid' => Money::sum($payments->pluck('amount'))], null);
            $payments->each->delete();
            $locked->approvals()->delete();
            $locked->delete();
        });
    }

    /**
     * Director correction of a paid line: the old payments are replaced by one payment for what was
     * paid (capped at the corrected total, the whole total when it was fully paid), on the last
     * payment's date and method. The before/after paid amounts go to the project edit log.
     *
     * @param  Collection<int, ExpensePayment>  $payments
     */
    private function repay(ExpenseRequest $line, Collection $payments, string $totalBefore, User $actor): void
    {
        $paidBefore = Money::sum($payments->pluck('amount'));
        $last = $payments->last();
        $wasFullyPaid = Money::compare($paidBefore, $totalBefore) >= 0;
        $payments->each->delete();
        $total = (string) $line->total_amount;
        $paid = $wasFullyPaid || Money::compare($paidBefore, $total) > 0 ? $total : $paidBefore;
        if (Money::compare($paid, '0') > 0) {
            $this->payments->record($line->id, [
                'amount' => $paid,
                'payment_date' => $last->payment_date instanceof \DateTimeInterface ? $last->payment_date->format('Y-m-d') : (string) $last->payment_date,
                'payment_method' => $last->payment_method,
                'transaction_reference' => $last->transaction_reference,
                'invoice_receipt_no' => $line->invoice_receipt_no,
            ], $actor);
        }
        $this->log($line, 'ledger_paid_line_corrected', $actor, ['paid' => $paidBefore], ['paid' => $paid]);
    }

    /** Adds a newly typed item name to the clean materials list (names only). */
    private function rememberMaterial(string $name, string $category): void
    {
        $label = trim(preg_replace('/\s+/', ' ', $name));
        if ($label === '' || mb_strlen($label) > 80) {
            return;
        }
        $code = Str::limit(Str::slug($label, '_'), 60, '');
        if ($code === '' || Lookup::where('type', 'material_item')->where(fn ($query) => $query->where('code', $code)->orWhereRaw('LOWER(label) = ?', [mb_strtolower($label)]))->exists()) {
            return;
        }
        Lookup::create(['type' => 'material_item', 'code' => $code, 'label' => $label, 'parent_type' => 'main_category', 'parent_code' => $category, 'sort_order' => 999, 'is_active' => true]);
    }

    /** Expenses go to the lowest level: a phase with subphases is recorded through one of its subphases. */
    private function assertLowestLevel(ProjectPhase $phase): void
    {
        if (ProjectPhase::query()->where('parent_id', $phase->id)->exists()) {
            throw ValidationException::withMessages(['project_phase_id' => '"'.$phase->stage_label.'" has subphases. Choose the subphase the expense belongs to.']);
        }
    }

    /** @return array{0: ?string, 1: ?string, 2: string} quantity, unit cost, total */
    private function amounts(array $data): array
    {
        $quantity = isset($data['quantity']) && $data['quantity'] !== '' ? number_format((float) $data['quantity'], 2, '.', '') : null;
        $unitCost = isset($data['unit_cost']) && $data['unit_cost'] !== '' ? number_format((float) $data['unit_cost'], 2, '.', '') : null;
        $total = isset($data['total_amount']) && $data['total_amount'] !== '' ? number_format((float) $data['total_amount'], 2, '.', '') : null;
        if ($total === null && $unitCost !== null) {
            $total = Money::product($unitCost, $quantity ?? '1');
        }

        return [$quantity, $unitCost, $total ?? '0.00'];
    }

    private function nextCode(Project $project): string
    {
        $prefix = self::codePrefix($project).'-TXN-';
        $max = ExpenseRequest::query()->where('request_code', 'like', $prefix.'%')->pluck('request_code')
            ->map(fn ($code) => (int) substr($code, strlen($prefix)))->max() ?? 0;

        return $prefix.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    private function log(ExpenseRequest $line, string $action, User $actor, ?array $before, ?array $after): void
    {
        ProjectEditLog::create([
            'project_id' => $line->project_id,
            'document_type' => 'expense',
            'document_id' => $line->id,
            'document_code' => $line->request_code,
            'action' => $action,
            'item_description' => $line->item_description,
            'reason' => 'Ledger correction',
            'before_snapshot' => $before,
            'after_snapshot' => $after,
            'edited_by' => $actor->id,
        ]);
    }

    /** @return array<string, string> code => label for every active unit (labels for display) */
    public static function units(): array
    {
        return Lookup::ofType('unit')->pluck('label', 'code')->all();
    }
}
