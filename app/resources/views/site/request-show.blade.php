@extends('layouts.app')
@section('title', $expenseRequest->request_code.' · Expense Request')
@section('content')
<a class="back-link" href="{{ route('site.projects.show', $project) }}">&larr; Back to {{ $project->name }}</a>
<div class="topline"><div><h2>{{ $expenseRequest->request_batch_code ?: $expenseRequest->request_code }}</h2><p>{{ $project->name }} · {{ $expenseRequest->phase?->stage_label }} · {{ $batchItems->count() }} item(s)</p></div><div class="request-badges"><span class="status-pill approval-{{ $expenseRequest->status }}">{{ ucwords(str_replace('_', ' ', $expenseRequest->status)) }}</span><span class="status-pill payment-{{ $expenseRequest->payment_status }}">{{ ucwords(str_replace('_', ' ', $expenseRequest->payment_status)) }}</span></div></div>

@if($batchItems->count() > 1)<section class="panel form-section"><h3>Requested Item List</h3><div class="tablewrap"><table><thead><tr><th>Line</th><th>Type</th><th>Description</th><th>Quantity</th><th>Site Estimate</th><th>Notes</th><th>Status</th></tr></thead><tbody>@foreach($batchItems as $item)<tr><td>{{ $item->request_line_number }}</td><td>{{ \App\Models\ExpenseRequest::TYPES[$item->request_type] ?? $item->request_type }}</td><td>{{ $item->item_description }}</td><td>{{ $item->quantity ?? 1 }} {{ $unitLabels[$item->unit] ?? $item->unit ?? 'LS' }}</td><td>{{ $item->requested_amount !== null ? 'TSh '.\App\Support\Money::format($item->requested_amount) : '—' }}</td><td>{{ $item->notes ?: '—' }}</td><td>{{ ucwords(str_replace('_',' ',$item->status)) }}</td></tr>@endforeach</tbody></table></div></section>@endif

<section class="panel form-section"><h3>Request Line Details</h3><div class="tiles request-detail-grid">
  <div class="tile"><div class="label">Project</div><div>{{ $project->name }} · {{ $project->project_code }}</div></div>
  <div class="tile"><div class="label">Phase</div><div>{{ $expenseRequest->phase?->stage_label }}</div></div>
  <div class="tile"><div class="label">Request Date</div><div>{{ $expenseRequest->requested_at?->format('d M Y') }}</div></div>
  <div class="tile"><div class="label">Submitted At</div><div>{{ $expenseRequest->submitted_at?->format('d M Y H:i') ?? $expenseRequest->created_at?->format('d M Y H:i') }}</div></div>
  <div class="tile"><div class="label">Requested By</div><div>{{ $expenseRequest->requestedBy?->name ?? '—' }}</div></div>
  <div class="tile"><div class="label">Request Type</div><div>{{ \App\Models\ExpenseRequest::TYPES[$expenseRequest->request_type] ?? $expenseRequest->request_type }}</div></div>
  <div class="tile"><div class="label">Labour / Subcontract Agreement</div><div>{{ $expenseRequest->labourAgreement ? $expenseRequest->labourAgreement->agreement_code.' · '.$expenseRequest->labourAgreement->fundi_subcontractor : 'Not linked' }}</div></div>
  <div class="tile"><div class="label">Category</div><div>{{ $categoryLabels[$expenseRequest->main_category] ?? $expenseRequest->main_category }} · {{ $subcategoryLabels[$expenseRequest->subcategory] ?? 'No subcategory' }}</div></div>
  <div class="tile"><div class="label">Description</div><div>{{ $expenseRequest->item_description }}</div></div>
  <div class="tile"><div class="label">Quantity / Unit</div><div>{{ $expenseRequest->quantity ?? 'Lump sum' }} · {{ $unitLabels[$expenseRequest->unit] ?? 'LS / no unit' }}</div></div>
  <div class="tile"><div class="label">Requested Amount</div><div>{{ $expenseRequest->requested_amount !== null ? 'TSh '.\App\Support\Money::format($expenseRequest->requested_amount) : '—' }}</div></div>
  @if($showFinancialDetails && $expenseRequest->unit_cost !== null)<div class="tile"><div class="label">Historical Unit Cost</div><div>TSh {{ \App\Support\Money::format($expenseRequest->unit_cost) }}</div></div>@endif
  @if($showFinancialDetails && $expenseRequest->total_amount !== null)<div class="tile"><div class="label">Historical Requested Total</div><div>{{ \App\Support\Money::format($expenseRequest->total_amount) }} TSh</div></div>@endif
  <div class="tile"><div class="label">Evidence</div><div>@if($expenseRequest->evidence_link)<a href="{{ route('site.requests.evidence', [$project, $expenseRequest]) }}">{{ $expenseRequest->evidence_original_name ?? 'Download supporting evidence' }}</a>@else — @endif</div></div>
  <div class="tile"><div class="label">Notes</div><div>{{ $expenseRequest->notes ?: '—' }}</div></div>
</div></section>

<section class="panel form-section"><h3>Approval History</h3>
@if($expenseRequest->approvals->isEmpty())<div class="phase-empty">No approval decision has been recorded.</div>@else
<div class="tablewrap"><table style="min-width:600px"><thead><tr><th>Stage</th><th>Decision</th><th>Decided By</th><th>Decision Date</th><th>Notes</th></tr></thead><tbody>@foreach($expenseRequest->approvals as $approval)<tr><td>{{ ucwords(str_replace('_',' ',$approval->approval_stage)) }}</td><td>{{ ucfirst($approval->decision) }}</td><td>{{ $approval->decidedBy?->name ?? '—' }}</td><td>{{ $approval->decided_at?->format('d M Y H:i') }}</td><td>{{ $approval->notes ?: '—' }}</td></tr>@endforeach</tbody></table></div>
@endif</section>

<section class="panel form-section"><h3>Payment Summary · Read Only</h3><div class="tiles"><div class="tile"><div class="label">Approval Status</div><div>{{ ucwords(str_replace('_', ' ', $expenseRequest->status)) }}</div></div><div class="tile"><div class="label">Payment Status</div><div>{{ ucwords(str_replace('_', ' ', $expenseRequest->payment_status)) }}</div></div>@if($showFinancialDetails)<div class="tile"><div class="label">Approved Amount</div><div>{{ $expenseRequest->status === 'approved' ? \App\Support\Money::format($expenseRequest->approved_payable_amount).' TSh' : 'Not approved' }}</div></div><div class="tile"><div class="label">Amount Paid</div><div>{{ \App\Support\Money::format($expenseRequest->paid_to_date) }} TSh</div></div><div class="tile"><div class="label">Approved Outstanding</div><div>{{ \App\Support\Money::format($expenseRequest->approved_outstanding_amount) }} TSh</div></div>@endif</div>
@if($showFinancialDetails && $expenseRequest->payments->isNotEmpty())<div class="tablewrap"><table style="min-width:650px"><thead><tr><th>Payment Date</th><th>Payment Method</th><th>Receipt</th><th>Amount</th><th>Recorded By</th></tr></thead><tbody>@foreach($expenseRequest->payments as $payment)<tr><td>{{ $payment->payment_date?->format('d M Y') }}</td><td>{{ ucfirst(str_replace('_', ' ', $payment->payment_method)) }}</td><td>{{ $payment->invoice_receipt_no ?: '—' }}</td><td class="amt">{{ \App\Support\Money::format($payment->amount) }}</td><td>{{ $payment->recordedBy?->name ?? '—' }}</td></tr>@endforeach</tbody></table></div>@endif
</section>

@if($canEdit)<div class="request-actions"><a class="btn btn-ghost" href="{{ route('site.requests.edit', [$project, $expenseRequest]) }}">Edit Pending Request</a><form method="POST" action="{{ route('site.requests.cancel', [$project, $expenseRequest]) }}" onsubmit="return confirm('Cancel this pending request? The request history will be retained.');">@csrf<button class="btn btn-ghost" type="submit">Cancel Request</button></form></div>@endif
@if($expenseRequest->status === 'cancelled')<section class="panel form-section"><h3>Cancellation</h3><p>Cancelled {{ $expenseRequest->cancelled_at?->format('d M Y H:i') ?? '—' }} by {{ $expenseRequest->cancelledBy?->name ?? '—' }}. Request history has been retained.</p></section>@endif
@endsection
