@extends('layouts.app')
@section('title', 'Director Projects · Buildiwick')
@section('content')
<div class="topline">
  <div><h2>Director Dashboard</h2><p>Start with a project, then open its detailed financial and construction analytics.</p></div>
  <div class="director-actions"><a class="btn btn-ghost" href="{{ route('director.receivables.index') }}">Receivables</a><a class="btn btn-ghost" href="{{ route('director.payables.index') }}">Payables</a><a class="btn btn-ghost" href="{{ route('director.transactions.index') }}">Transactions</a></div>
</div>

<section class="tiles director-alerts">
  <div class="tile"><div class="label">Projects shown</div><div class="value">{{ $projects->count() }}</div><div class="sub">Open a project for its analytics</div></div>
  <div class="tile"><div class="label">Over-budget projects</div><div class="value">{{ $actionCounts['over_budget'] }}</div><div class="sub">Approved commitments exceed budget</div></div>
  <a class="tile alert-link" href="{{ route('director.receivables.index') }}"><div class="label">Receivable Attention</div><div class="value">{{ $actionCounts['overdue_invoices'] }} overdue</div><div class="sub">TSh {{ \App\Support\Money::format($actionCounts['overdue_receivables']) }} outstanding</div></a>
</section>

<form method="GET" class="panel director-filter form-section">
  <div class="row3">
    <div class="field"><label for="search">Find a project</label><input id="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Name, code, or client"></div>
    <div class="field"><label for="status">Project status</label><select id="status" name="status"><option value="all">All statuses</option>@foreach(['active' => 'Active', 'planning' => 'Planning', 'on_hold' => 'On Hold', 'completed' => 'Completed'] as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? 'all') === $value)>{{ $label }}</option>@endforeach</select></div>
    <div class="field filter-action"><button class="btn btn-primary" type="submit">Find Projects</button></div>
  </div>
</form>

<div class="tablewrap"><table><thead><tr><th>Project</th><th>Client</th><th>Current Phase</th><th>Status</th><th>Attention</th><th>Action</th></tr></thead><tbody>
@forelse($projects as $row)
  <tr><td class="name">{{ $row['project']->name }}<small>{{ $row['project']->project_code }}</small></td><td>{{ $row['project']->client?->name ?? '—' }}</td><td>{{ $row['current_phase'] ?? '—' }}</td><td><span class="dot-status {{ $row['project']->status === 'active' ? 'active' : 'zero' }}">{{ ucfirst(str_replace('_', ' ', $row['project']->status)) }}</span></td><td>@if($row['over_budget'])<span class="badge warn">Over budget</span>@elseif($row['no_budget'])<span class="badge warn">No budget set</span>@else<span class="badge good">On budget</span>@endif</td><td><a class="btn btn-ghost" href="{{ route('director.projects.show', [$row['project'], 'range' => $range['name']]) }}">View Project</a></td></tr>
@empty<tr><td colspan="6">No projects match this view.</td></tr>@endforelse
</tbody></table></div>
<style>
.director-actions{display:flex;gap:8px;flex-wrap:wrap}.alert-link{text-decoration:none}.alert-link:hover{border-color:var(--primary)}.director-filter .row3{align-items:end}.filter-action{padding-bottom:13px;margin:0}.tablewrap td small{display:block;color:var(--ink-dim);font-size:10px;margin-top:3px}@media(max-width:700px){.director-filter .row3{grid-template-columns:1fr}.director-actions{width:100%}}
</style>
@endsection
