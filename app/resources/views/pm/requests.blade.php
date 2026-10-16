@extends('layouts.app')
@section('title', 'Project Requests · Project Manager')
@section('content')
<div class="topline"><div><h2>Project Requests</h2><p>Review Site item lists, prepare commercial details, group items by supplier, allocate the internal budget and approve directly for Accounts.</p></div><div class="estimate-actions"><a class="btn btn-ghost" href="{{ route('pm.requests.export', array_filter(['project_id'=>$filters['project_id'] ?? null,'category'=>$filters['category'] ?? null])) }}">Download Excel</a><a class="btn btn-ghost" href="{{ route('pm.projects.index') }}">Projects</a></div></div>

<section class="panel form-section request-workflow"><strong>For each item</strong><span>1. Choose its budget line</span><span>2. Choose supplier and final price</span><span>3. Save, check, then approve for Accounts</span></section>

<section class="panel form-section"><h3>Create a Request</h3><p class="form-context">Choose an assigned project. PM preparation and approval sends completed items directly to Accounts.</p><div class="estimate-actions">@foreach($projects as $project)<a class="btn btn-primary" href="{{ route('pm.requests.create', $project) }}">{{ $project->name }}</a>@endforeach</div><div class="category-guide">@foreach(\App\Models\ExpenseRequest::CATEGORY_GUIDANCE as $code => $guidance)<div><strong>{{ $labels[$code] ?? $code }}</strong><span>{{ $guidance }}</span></div>@endforeach</div></section>

<div class="panel form-section request-tabs">
@foreach(['all' => 'All', 'awaiting_pricing' => 'Awaiting Pricing', 'ready' => 'Ready for PM Review', 'approved' => 'PM Approved / Accounts', 'rejected' => 'Rejected'] as $value => $label)
  <a class="btn {{ $filter === $value ? 'btn-primary' : 'btn-ghost' }}" href="{{ route('pm.requests.index', array_filter(['filter' => $value, 'project_id' => $filters['project_id'] ?? null, 'phase_id' => $filters['phase_id'] ?? null, 'category' => $filters['category'] ?? null])) }}">{{ $label }} <span class="queue-count">{{ $counts[$value] }}</span></a>
@endforeach
</div>

<form method="GET" class="panel form-section"><input type="hidden" name="filter" value="{{ $filter }}"><div class="row3">
  <div class="field"><label for="project_id">Project</label><select id="project_id" name="project_id"><option value="">All projects</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected((int) ($filters['project_id'] ?? 0) === $project->id)>{{ $project->name }}</option>@endforeach</select></div>
  <div class="field"><label for="phase_id">Construction phase</label><select id="phase_id" name="phase_id"><option value="">All phases</option>@foreach($phases->groupBy('project_id') as $projectId => $projectPhases)<optgroup label="{{ $projects->firstWhere('id', $projectId)?->name }}">@foreach($projectPhases as $phase)<option value="{{ $phase->id }}" @selected((int) ($filters['phase_id'] ?? 0) === $phase->id)>{{ $phase->stage_label }}</option>@endforeach</optgroup>@endforeach</select></div>
  <div class="field"><label for="category">Category</label><select id="category" name="category"><option value="">All categories</option>@foreach($labels as $value => $label)<option value="{{ $value }}" @selected(($filters['category'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
</div><div class="estimate-actions"><button class="btn btn-primary" type="submit">Apply Filters</button><a class="btn btn-ghost" href="{{ route('pm.requests.index', ['filter' => $filter]) }}">Clear Filters</a></div></form>

@if($batches->isEmpty())
<section class="panel empty-state"><h3>No project requests match this view.</h3><p>Change the filters, create a PM request, or wait for a Site Operator submission.</p></section>
@else
<div class="tablewrap"><table><thead><tr><th>Request</th><th>Project</th><th>Items</th><th>Supplier Groups</th><th>Submitted By</th><th>Submitted At</th><th>Request Total</th><th>Workflow Status</th><th>Action</th></tr></thead><tbody>
@foreach($batches as $batch)<tr><td><strong>{{ $batch->code }}</strong></td><td><a href="{{ route('pm.projects.show',$batch->anchor->project) }}">{{ $batch->anchor->project->name }}</a><br><small>{{ $batch->anchor->project->project_code }}</small></td><td>{{ $batch->item_count }}</td><td>{{ $batch->supplier_count ?: 'Not grouped yet' }}</td><td>{{ $batch->anchor->requestedBy?->name ?? '—' }}</td><td>{{ $batch->anchor->submitted_at?->format('d M Y H:i') }}</td><td class="amt">{{ $batch->state === 'awaiting_pricing' ? 'Awaiting pricing' : 'TSh '.\App\Support\Money::format($batch->total) }}</td><td><span class="status-pill approval-{{ $batch->state === 'ready' ? 'pending_approval' : $batch->state }}">{{ ucwords(str_replace('_',' ',$batch->state)) }}</span></td><td><a href="{{ route('pm.procurement.show', $batch->anchor) }}">Open Workflow</a></td></tr>@endforeach</tbody></table></div>
@endif
<style>.request-tabs,.request-workflow{display:flex;gap:8px;flex-wrap:wrap}.request-workflow{gap:14px;align-items:center}.request-workflow span{color:var(--ink-soft);font-size:12px}.queue-count{background:rgba(0,0,0,.08);border-radius:99px;padding:1px 6px}.btn-primary .queue-count{background:rgba(255,255,255,.2)}.category-guide{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px;margin-top:16px}.category-guide div{padding:11px;border:1px solid var(--line);border-radius:8px}.category-guide strong,.category-guide span{display:block}.category-guide span{font-size:11.5px;color:var(--ink-dim);margin-top:4px;line-height:1.5}</style>
@endsection
