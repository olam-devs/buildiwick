@extends('layouts.app')
@section('title', $project->name.' · Site Requests')
@section('content')
<a class="back-link" href="{{ route('site.index') }}">&larr; Back to Site Operator Dashboard</a>
<div class="topline"><div><h2>{{ $project->name }}</h2><p>{{ $project->project_code }} · {{ $project->location ?? 'Location not set' }}</p></div>
@if($activeCount === 1)<a class="btn btn-primary" href="{{ route('site.requests.create', $project) }}">+ New Expense Request</a>@endif
</div>

@if($activeCount === 1)<section class="panel form-section"><h3>Raise a Request by Cost Area</h3><div class="estimate-actions"><a class="btn btn-ghost" href="{{ route('site.requests.create', [$project, 'category'=>'materials', 'type'=>'item']) }}">Materials</a><a class="btn btn-ghost" href="{{ route('site.requests.create', [$project, 'category'=>'labour_subcontracts', 'type'=>'salary_wage']) }}">Salary / Wages</a><a class="btn btn-ghost" href="{{ route('site.requests.create', [$project, 'category'=>'labour_subcontracts', 'type'=>'service']) }}">Labour / Subcontract</a><a class="btn btn-ghost" href="{{ route('site.requests.create', [$project, 'category'=>'logistics_equipment', 'type'=>'service']) }}">Logistics / Equipment</a><a class="btn btn-ghost" href="{{ route('site.requests.create', [$project, 'category'=>'preliminaries_overheads', 'type'=>'service']) }}">Preliminaries / Overheads</a></div></section>@endif

<section class="panel form-section"><h3>Project</h3><div class="tiles">
  <div class="tile"><div class="label">Name</div><div>{{ $project->name }}</div></div>
  <div class="tile"><div class="label">Code</div><div>{{ $project->project_code }}</div></div>
  <div class="tile"><div class="label">Location</div><div>{{ $project->location ?? '—' }}</div></div>
  <div class="tile"><div class="label">Current Phase</div><div>{{ $currentPhase?->stage_label ?? ($activeCount === 0 ? 'Phase not configured' : 'Multiple active phases') }}</div></div>
  <div class="tile"><div class="label">Project Status</div><div>{{ ucfirst(str_replace('_', ' ', $project->status)) }}</div></div>
</div>
@if($activeCount !== 1)<div class="flash error">Request creation is blocked: {{ $activeCount === 0 ? 'no active construction phase is configured.' : 'multiple active construction phases are configured.' }} Ask an administrator to resolve the project phase.</div>@endif
</section>

<section class="tiles request-summary">
  <div class="tile"><div class="label">Total Requests</div><div class="value">{{ $summary['total_requests'] }}</div></div>
  <div class="tile"><div class="label">Pending Requests</div><div class="value">{{ $summary['pending_count'] }}</div></div>
  <div class="tile"><div class="label">Approved Requests</div><div class="value">{{ $summary['approved_count'] }}</div></div>
  <div class="tile"><div class="label">Rejected Requests</div><div class="value">{{ $summary['rejected_count'] }}</div></div>
  <div class="tile"><div class="label">Awaiting PM Review</div><div class="value">{{ $summary['awaiting_pricing_count'] }}</div></div>
  @if($showFinancialDetails && $summary['requested'] !== '0.00')<div class="tile"><div class="label">Total Requested (Historical Priced)</div><div class="value">{{ \App\Support\Money::format($summary['requested']) }}</div></div>@endif
</section>

<section class="panel form-section"><h3>Category Summary · Request Counts</h3><div class="tablewrap"><table style="min-width:780px;"><thead><tr><th>Category</th><th>Total Requests</th><th>Pending</th><th>Approved</th><th>Rejected</th></tr></thead><tbody>
@foreach($summary['categories'] as $category)<tr><td>{{ $category['label'] }}</td><td class="amt">{{ $category['requests'] }}</td><td class="amt">{{ $category['pending'] }}</td><td class="amt">{{ $category['approved'] }}</td><td class="amt">{{ $category['rejected'] }}</td></tr>@endforeach
</tbody></table></div></section>

<section class="panel form-section"><h3>Expense Requests</h3>
@if($requestBatches->isEmpty())<div class="phase-empty">No expense requests have been submitted for this project yet.@if($activeCount === 1) <a href="{{ route('site.requests.create', $project) }}">Create the first request</a>.@endif</div>
@else
<p class="form-context">Each row is one complete request. Open it to see all requested items and their approval progress.</p>
<div class="tablewrap"><table class="site-request-table"><thead><tr><th>Request</th><th>Date</th><th>Phase</th><th>Items</th><th>PM Result</th><th>Action</th></tr></thead><tbody>
@foreach($requestBatches as $batch)
@php($item=$batch->anchor)
<tr>
  <td><a href="{{ route('site.requests.show', [$project, $item]) }}">{{ $batch->code }}</a></td><td>{{ $item->requested_at?->format('d M Y') }}</td><td>{{ $item->phase?->stage_label ?? 'Multiple / not set' }}</td>
  <td>{{ $batch->item_count }}</td>
  <td>@foreach($batch->statuses as $status)<span class="status-pill approval-{{ $status }}">{{ ucwords(str_replace('_', ' ', $status)) }}</span> @endforeach</td><td><a href="{{ route('site.requests.show', [$project, $item]) }}">Open Request</a></td>
</tr>
@endforeach
</tbody></table></div>
@endif
</section>
@endsection
