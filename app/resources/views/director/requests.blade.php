@extends('layouts.app')
@section('title', 'Request Approvals · Director')
@section('content')
<div class="topline"><div><h2>Request Approvals</h2><p>Final review after Project Manager approval. Only requests approved here become visible to Accounts for payment.</p></div><a class="btn btn-ghost" href="{{ route('director.index') }}">Executive Dashboard</a></div>

<form method="GET" class="panel form-section"><div class="row3">
  <div class="field"><label for="project_id">Project</label><select id="project_id" name="project_id"><option value="">All projects</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected((int)($filters['project_id'] ?? 0) === $project->id)>{{ $project->name }}</option>@endforeach</select></div>
  <div class="field"><label for="request_type">Request Type</label><select id="request_type" name="request_type"><option value="">All types</option>@foreach(\App\Models\ExpenseRequest::TYPES as $value => $label)<option value="{{ $value }}" @selected(($filters['request_type'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
  <div class="field"><label for="category">Budget Category</label><select id="category" name="category"><option value="">All categories</option>@foreach(\App\Services\ProjectManagerBudgetService::CATEGORIES as $value => $label)<option value="{{ $value }}" @selected(($filters['category'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
</div><div class="estimate-actions"><button class="btn btn-primary" type="submit">Apply Filters</button><a class="btn btn-ghost" href="{{ route('director.requests.index') }}">Clear</a></div></form>

@forelse($requests as $item)
@php($impact = $impacts[$item->id])
<section class="panel form-section director-request-card">
  <div class="topline"><div><h3>{{ $item->request_code }} · {{ \App\Models\ExpenseRequest::TYPES[$item->request_type] ?? $item->request_type }}</h3><p>{{ $item->project->name }} · {{ $item->phase?->stage_label }} · submitted by {{ $item->requestedBy?->name ?? '—' }}</p></div><span class="status-pill approval-{{ $item->status }}">Awaiting Director</span></div>
  <div class="tiles request-detail-grid">
    <div class="tile"><div class="label">Category / Subcategory</div><div>{{ \App\Services\ProjectManagerBudgetService::CATEGORIES[$item->main_category] ?? $item->main_category }} · {{ $item->subcategory ?? 'No subcategory' }}</div></div>
    <div class="tile"><div class="label">Description</div><div>{{ $item->item_description }}</div></div>
    <div class="tile"><div class="label">Quantity / Unit</div><div>{{ $item->quantity ?? '1.00' }} · {{ $item->unit ?? 'LS' }}</div></div>
    <div class="tile"><div class="label">Amount Requested</div><div>{{ $item->requested_amount !== null ? 'TSh '.\App\Support\Money::format($item->requested_amount) : 'Not stated by originator' }}</div></div>
    <div class="tile"><div class="label">Supplier / Payee</div><div>{{ $item->supplierPayee?->name ?? '—' }}</div></div>
    <div class="tile"><div class="label">PM Recommended Amount</div><div>TSh {{ \App\Support\Money::format($item->commercial_total) }}</div></div>
    <div class="tile"><div class="label">Payment Method / Destination</div><div>{{ ucwords(str_replace('_',' ',$item->commercial_payment_method ?? '—')) }} · {{ $item->commercialPaymentDestination?->account_wallet_number ?? $item->commercial_payment_destination ?? '—' }}</div></div>
    <div class="tile"><div class="label">PM Approval</div><div>{{ $item->latestPmApproval?->decidedBy?->name ?? '—' }} · {{ $item->latestPmApproval?->decided_at?->format('d M Y H:i') }}<br>{{ $item->latestPmApproval?->notes }}</div></div>
  </div>
  <h4>Budget Impact</h4><div class="tiles"><div class="tile"><div class="label">Internal Budget</div><div>TSh {{ \App\Support\Money::format($impact['budget']) }}</div></div><div class="tile"><div class="label">Other Commitments</div><div>TSh {{ \App\Support\Money::format($impact['approved_commitments']) }}</div></div><div class="tile"><div class="label">After This Request</div><div>TSh {{ \App\Support\Money::format($impact['projected']) }}</div></div><div class="tile"><div class="label">Remaining</div><div class="{{ $impact['over_budget'] ? 'over-budget' : '' }}">TSh {{ \App\Support\Money::format($impact['remaining']) }}</div></div></div>
  @if($impact['over_budget'])<div class="flash error">This request would exceed the matching internal-budget category by TSh {{ \App\Support\Money::format($impact['over_budget_amount']) }}.</div>@endif
  <div class="director-decision-grid"><form method="POST" action="{{ route('director.requests.decision', $item) }}">@csrf<input type="hidden" name="decision" value="approve"><div class="field"><label>Director Approval Notes <span class="req">*</span></label><textarea name="notes" rows="3" required></textarea></div><button class="btn btn-primary" type="submit">Approve for Accounts</button></form><form method="POST" action="{{ route('director.requests.decision', $item) }}">@csrf<input type="hidden" name="decision" value="reject"><div class="field"><label>Director Rejection Reason <span class="req">*</span></label><textarea name="notes" rows="3" required></textarea></div><button class="btn btn-ghost" type="submit">Reject Request</button></form></div>
</section>
@empty
<section class="panel empty-state"><h3>No requests are awaiting Director approval.</h3><p>Requests appear here only after a Project Manager has completed the commercial details and approved them.</p></section>
@endforelse
<style>.director-request-card{margin-bottom:14px}.director-decision-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:18px}@media(max-width:750px){.director-decision-grid{grid-template-columns:1fr}}</style>
@endsection
