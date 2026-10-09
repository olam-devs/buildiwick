@extends('layouts.app')
@section('title', 'Site Requests · Project Manager')
@section('content')
<div class="topline"><div><h2>Site Requests</h2><p>Review requests by project, construction phase, category, and status; prepare commercial details and record PM decisions.</p></div><a class="btn btn-ghost" href="{{ route('pm.projects.index') }}">Projects</a></div>

<div class="panel form-section request-tabs">
@foreach(['all' => 'All', 'awaiting_pricing' => 'Awaiting Pricing', 'ready' => 'Ready for Review', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $value => $label)
  <a class="btn {{ $filter === $value ? 'btn-primary' : 'btn-ghost' }}" href="{{ route('pm.requests.index', array_filter(['filter' => $value, 'project_id' => $filters['project_id'] ?? null, 'phase_id' => $filters['phase_id'] ?? null, 'category' => $filters['category'] ?? null])) }}">{{ $label }} <span class="queue-count">{{ $counts[$value] }}</span></a>
@endforeach
</div>

<form method="GET" class="panel form-section"><input type="hidden" name="filter" value="{{ $filter }}"><div class="row3">
  <div class="field"><label for="project_id">Project</label><select id="project_id" name="project_id"><option value="">All projects</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected((int) ($filters['project_id'] ?? 0) === $project->id)>{{ $project->name }}</option>@endforeach</select></div>
  <div class="field"><label for="phase_id">Construction phase</label><select id="phase_id" name="phase_id"><option value="">All phases</option>@foreach($phases->groupBy('project_id') as $projectId => $projectPhases)<optgroup label="{{ $projects->firstWhere('id', $projectId)?->name }}">@foreach($projectPhases as $phase)<option value="{{ $phase->id }}" @selected((int) ($filters['phase_id'] ?? 0) === $phase->id)>{{ $phase->stage_label }}</option>@endforeach</optgroup>@endforeach</select></div>
  <div class="field"><label for="category">Category</label><select id="category" name="category"><option value="">All categories</option>@foreach($labels as $value => $label)<option value="{{ $value }}" @selected(($filters['category'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
</div><div class="estimate-actions"><button class="btn btn-primary" type="submit">Apply Filters</button><a class="btn btn-ghost" href="{{ route('pm.requests.index', ['filter' => $filter]) }}">Clear Filters</a></div></form>

@if($requests->isEmpty())
<section class="panel empty-state"><h3>No Site Requests match this view.</h3><p>Change the filters or wait for a Site Operator to submit a request.</p></section>
@else
<div class="tablewrap"><table><thead><tr><th>Request Code</th><th>Project</th><th>Phase</th><th>Category</th><th>Item</th><th>Quantity</th><th>Unit</th><th>Submitted By</th><th>Submitted At</th><th>Commercial Status</th><th>Approval Status</th><th>Action</th></tr></thead><tbody>
@foreach($requests as $request)<tr><td>{{ $request->request_code }}</td><td>{{ $request->project->name }}</td><td>{{ $request->phase?->stage_label ?? '—' }}</td><td>{{ $labels[$request->main_category] ?? $request->main_category }}</td><td>{{ $request->item_description }}</td><td class="amt">{{ $request->quantity ?? '1.00' }}</td><td>{{ $request->unit ?? 'LS' }}</td><td>{{ $request->requestedBy?->name ?? '—' }}</td><td>{{ $request->submitted_at?->format('d M Y H:i') }}</td><td>{{ $request->commercial_total !== null ? 'Ready for Review' : 'Awaiting Pricing' }}</td><td><span class="status-pill approval-{{ $request->status }}">{{ ucwords(str_replace('_',' ',$request->status)) }}</span></td><td><a href="{{ route('pm.requests.commercial.edit', $request) }}">Review</a></td></tr>@endforeach</tbody></table></div>
@endif
<style>.request-tabs{display:flex;gap:8px;flex-wrap:wrap}.queue-count{background:rgba(0,0,0,.08);border-radius:99px;padding:1px 6px}.btn-primary .queue-count{background:rgba(255,255,255,.2)}</style>
@endsection
