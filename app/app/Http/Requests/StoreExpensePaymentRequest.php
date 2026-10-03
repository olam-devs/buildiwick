<?php

namespace App\Http\Requests;

use App\Models\ExpenseRequest;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpensePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $request = $this->route('expenseRequest');

        return $request instanceof ExpenseRequest && ($this->user()?->can('recordPayment', $request) ?? false);
    }

    public function rules(): array
    {
        return [
            'expense_request_id' => ['required', Rule::exists('expense_requests', 'id')->where('status', 'approved')],
            'project_id' => ['required', Rule::exists('projects', 'id')],
            'project_phase_id' => ['required', Rule::exists('project_phases', 'id')->where('project_id', $this->input('project_id'))],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'idempotency_key' => ['required', 'uuid'],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'payment_method' => ['required', Rule::exists('lookups', 'code')->where('type', 'payment_method')],
            'transaction_reference' => ['nullable', 'string', 'max:255'],
            'variance_reason' => ['nullable', 'string', 'max:2000'],
            'invoice_receipt_no' => ['nullable', 'string', 'max:100'],
            'evidence' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx', 'extensions:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx', 'max:20480'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            $existingPayment = \App\Models\ExpensePayment::query()->where('idempotency_key', $this->input('idempotency_key'))->first();
            if ($existingPayment) {
                $routeRequest = $this->route('expenseRequest');
                if (! $routeRequest instanceof ExpenseRequest
                    || (int) $this->input('expense_request_id') !== (int) $routeRequest->id
                    || (int) $existingPayment->expense_request_id !== (int) $routeRequest->id
                    || (int) $existingPayment->recorded_by !== (int) $this->user()?->id) {
                    $validator->errors()->add('idempotency_key', 'This payment submission key has already been used.');
                }

                return;
            }

            $request = ExpenseRequest::with('payments')->find($this->input('expense_request_id'));
            if (! $request) {
                return;
            }
            $routeRequest = $this->route('expenseRequest');
            if ($routeRequest instanceof ExpenseRequest && (int) $routeRequest->id !== (int) $request->id) {
                $validator->errors()->add('expense_request_id', 'The payment request must match the request in the route.');
            }
            if ((int) $request->project_id !== (int) $this->input('project_id') || (int) $request->project_phase_id !== (int) $this->input('project_phase_id')) {
                $validator->errors()->add('expense_request_id', 'The payment project and phase must match the expense request.');
            }
            $alreadyPaid = Money::sum($request->payments->pluck('amount'));
            $newTotal = Money::sum([$alreadyPaid, $this->input('amount', '0')]);
            if (Money::compare($newTotal, $request->approved_payable_amount) > 0) {
                $validator->errors()->add('amount', 'The payment cannot exceed the outstanding request amount.');
            }
            $approvedMethod = $request->latestApproval?->payment_method_snapshot ?? $request->commercial_payment_method;
            if ($approvedMethod && $approvedMethod !== $this->input('payment_method') && ! trim((string) $this->input('variance_reason'))) {
                $validator->errors()->add('variance_reason', 'Explain why the actual payment method differs from the approved method.');
            }
        }];
    }
}