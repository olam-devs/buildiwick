<?php

namespace App\Services;

use App\Models\ClientInvoice;
use App\Models\ClientPayment;
use App\Models\ExpensePayment;
use App\Models\ExpenseRequest;
use App\Models\Project;
use App\Support\Money;
use Illuminate\Support\Collection;

class AccountantReportingService
{
    public function transactions(?Project $project = null, array $filters = []): Collection
    {
        $incoming = ClientPayment::query()->with(['project', 'client', 'invoice', 'recordedBy'])
            ->when($project, fn ($query) => $query->where('project_id', $project->id))
            ->get()->map(fn (ClientPayment $payment) => [
                'date' => $payment->payment_date,
                'timestamp' => $payment->created_at,
                'id' => $payment->id,
                'reference' => $payment->payment_code,
                'type' => 'Client Payment',
                'description' => $payment->client?->name ?? 'Client payment',
                'phase' => null,
                'category' => null,
                'in' => $payment->amount,
                'out' => '0.00',
                'recorded_by' => $payment->recordedBy?->name ?? '—',
                'source' => $payment,
            ]);
        $outgoing = ExpensePayment::query()->with(['project', 'phase', 'expenseRequest.supplierPayee', 'recordedBy'])
            ->when($project, fn ($query) => $query->where('project_id', $project->id))
            ->get()->map(fn (ExpensePayment $payment) => [
                'date' => $payment->payment_date,
                'timestamp' => $payment->created_at,
                'id' => $payment->id,
                'reference' => $payment->payment_code,
                'type' => 'Expense Payment',
                'description' => $payment->expenseRequest?->supplierPayee?->name ?? $payment->expenseRequest?->item_description ?? 'Project expense payment',
                'phase' => $payment->phase?->stage_label,
                'category' => $payment->expenseRequest?->main_category,
                'in' => '0.00',
                'out' => $payment->amount,
                'recorded_by' => $payment->recordedBy?->name ?? '—',
                'source' => $payment,
            ]);
        $events = $incoming->concat($outgoing)->filter(fn (array $event) => $this->matches($event, $filters));
        $events = $events->sortBy([['date', 'asc'], ['timestamp', 'asc'], ['id', 'asc']])->values();
        $balance = '0.00';
        return $events->map(function (array $event) use (&$balance): array {
            $balance = Money::sum([$balance, $event['in'], Money::difference('0.00', $event['out'])]);
            $event['balance'] = $balance;
            return $event;
        });
    }

    public function receivables(array $filters = []): Collection
    {
        return ClientInvoice::query()->with(['project', 'client', 'payments'])->where('status', 'issued')->get()
            ->filter(fn (ClientInvoice $invoice) => Money::compare($invoice->outstanding_amount, '0') > 0 && $this->matchesInvoice($invoice, $filters))
            ->map(function (ClientInvoice $invoice): ClientInvoice { $days = $invoice->due_at?->isBefore(today()) ? $invoice->due_at->diffInDays(today()) : 0; $invoice->setAttribute('age_bucket', $days === 0 ? 'Current' : ($days <= 30 ? '1–30 Days Overdue' : ($days <= 60 ? '31–60 Days' : ($days <= 90 ? '61–90 Days' : '90+ Days')))); return $invoice; })->values();
    }

    public function payables(array $filters = []): Collection
    {
        return ExpenseRequest::query()->with(['project', 'supplierPayee', 'latestApproval', 'payments'])->where('status', 'approved')->get()
            ->filter(fn (ExpenseRequest $request) => Money::compare($request->approved_outstanding_amount, '0') > 0 && $this->matchesRequest($request, $filters))
            ->map(function (ExpenseRequest $request): ExpenseRequest { $days = $request->latestApproval?->decided_at?->diffInDays(today()) ?? 0; $request->setAttribute('approval_age_bucket', $days <= 7 ? '0–7 days' : ($days <= 14 ? '8–14 days' : ($days <= 30 ? '15–30 days' : '30+ days'))); return $request; })->values();
    }

    public function statement(Project $project, array $filters = []): array
    {
        $all = $this->transactions($project);
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $opening = $all->filter(fn (array $event) => (! $from || $event['date']->lt($from)))->last()['balance'] ?? '0.00';
        $period = $all->filter(fn (array $event) => (! $from || ! $event['date']->lt($from)) && (! $to || ! $event['date']->gt($to)))->values();
        $balance = $opening;
        $period = $period->map(function (array $event) use (&$balance): array {
            $balance = Money::sum([$balance, $event['in'], Money::difference('0.00', $event['out'])]);
            $event['balance'] = $balance;
            return $event;
        });
        return ['opening' => $opening, 'in' => Money::sum($period->pluck('in')), 'out' => Money::sum($period->pluck('out')), 'closing' => $balance, 'events' => $period];
    }

    private function matches(array $event, array $filters): bool
    {
        if (! empty($filters['from']) && $event['date']->lt($filters['from'])) return false;
        if (! empty($filters['to']) && $event['date']->gt($filters['to'])) return false;
        if (($filters['direction'] ?? null) === 'in' && Money::compare($event['in'], '0') <= 0) return false;
        if (($filters['direction'] ?? null) === 'out' && Money::compare($event['out'], '0') <= 0) return false;
        if (! empty($filters['phase']) && $event['phase'] !== $filters['phase']) return false;
        if (! empty($filters['category']) && $event['category'] !== $filters['category']) return false;
        return true;
    }

    private function matchesInvoice(ClientInvoice $invoice, array $filters): bool
    {
        return (! empty($filters['project_id']) ? (int) $invoice->project_id === (int) $filters['project_id'] : true);
    }

    private function matchesRequest(ExpenseRequest $request, array $filters): bool
    {
        return (! empty($filters['project_id']) ? (int) $request->project_id === (int) $filters['project_id'] : true)
            && (! empty($filters['category']) ? $request->main_category === $filters['category'] : true);
    }
}
