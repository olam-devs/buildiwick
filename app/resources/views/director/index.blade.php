@extends('layouts.app')
@section('title', 'Director Projects · Buildiwick')
@section('content')
<div class="topline">
  <div><h2>Director Dashboard</h2><p>Start with a project, then open its detailed financial and construction analytics.</p></div>
  <div class="director-actions"><a class="btn btn-ghost" href="{{ route('director.receivables.index') }}">Receivables</a><a class="btn btn-ghost" href="{{ route('director.payables.index') }}">Payables</a><a class="btn btn-ghost" href="{{ route('director.transactions.index') }}">Transactions</a></div>
</div>

<section class="tiles director-alerts">
  <div class="tile"><div class="label">Projects shown</div><div class="value">{{ $projects->count() }}</div><div class="sub">Open a project for its analytics</div></div>
  <a class="tile alert-link" href="{{ route('admin.projects.index') }}"><div class="label">Projects Needing Setup</div><div class="value">{{ $actionCounts['setup_incomplete'] }}</div><div class="sub">Missing client or a required role assignment</div></a>
  <a class="tile alert-link" href="{{ route('estimator.projects.index', ['status' => 'submitted']) }}"><div class="label">Estimates Awaiting Approval</div><div class="value">{{ $actionCounts['estimates_awaiting'] }}</div><div class="sub">Submitted by Estimators for your review</div></a>
  <a class="tile alert-link" href="#director-projects"><div class="label">Projects with Warnings</div><div class="value">{{ $actionCounts['projects_with_warnings'] }}</div><div class="sub">Based on your warning rules</div></a>
  <div class="tile"><div class="label">Over-budget projects</div><div class="value">{{ $actionCounts['over_budget'] }}</div><div class="sub">Approved commitments exceed budget</div></div>
  <a class="tile alert-link" href="{{ route('director.receivables.index') }}"><div class="label">Receivable Attention</div><div class="value">{{ $actionCounts['overdue_invoices'] }} overdue</div><div class="sub">TSh {{ \App\Support\Money::format($actionCounts['overdue_receivables']) }} outstanding</div></a>
</section>

<form method="GET" class="panel form-section director-period-form">
  @foreach(['search', 'status'] as $keep)@if(!empty($filters[$keep]))<input type="hidden" name="{{ $keep }}" value="{{ $filters[$keep] }}">@endif @endforeach
  <div class="date-filter"><div class="field"><label for="range">Review period</label><select name="range" id="range">@foreach(\App\Support\ReviewPeriod::PRESETS as $value => $label)<option value="{{ $value }}" @selected($range['name'] === $value)>{{ $label }}</option>@endforeach</select></div><div class="field"><label for="from">From</label><input type="date" id="from" name="from" value="{{ $range['name'] === 'custom' ? $range['from']->toDateString() : '' }}"></div><div class="field"><label for="to">To</label><input type="date" id="to" name="to" value="{{ $range['name'] === 'custom' ? $range['to']->toDateString() : '' }}"></div><div class="field"><button class="btn btn-primary" type="submit">Review</button></div></div>
</form>
@include('director.partials.period-comparison', ['current' => $periodSummary, 'previous' => $previousSummary])

<section class="panel form-section">
  <div class="section-heading"><h3>Company KPI Targets</h3><a class="btn btn-ghost" href="{{ route('director.kpis.index') }}">Manage Company Targets</a></div>
  @if($currentKpis->isEmpty())
    <p class="form-context">No company targets cover today yet. Open <strong>Company KPI Targets</strong> in the sidebar to set targets for this month, quarter or year. Project targets are set inside each project under <strong>Warnings &amp; KPIs</strong>.</p>
  @else
    <div class="tablewrap"><table style="min-width:800px"><thead><tr><th>Measure</th><th>Period</th><th class="amt">Target</th><th class="amt">Actual</th><th>Progress</th><th>Status</th></tr></thead><tbody>
    @foreach($currentKpis as $kpi)@include('director.partials.kpi-row', ['row' => $kpi])@endforeach
    </tbody></table></div>
  @endif
</section>
@include('director.partials.kpi-styles')

<form method="GET" class="panel director-filter form-section">
  <div class="row3">
    <div class="field"><label for="search">Find a project</label><input id="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Name, code, or client"></div>
    <div class="field"><label for="status">Project status</label><select id="status" name="status"><option value="all">All statuses</option>@foreach(['active' => 'Active', 'planning' => 'Planning', 'on_hold' => 'On Hold', 'completed' => 'Completed'] as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? 'all') === $value)>{{ $label }}</option>@endforeach</select></div>
    <div class="field filter-action"><button class="btn btn-primary" type="submit">Find Projects</button></div>
  </div>
</form>

<div class="tablewrap" id="director-projects"><table><thead><tr><th>Project</th><th>Client</th><th>Current Phase</th><th>Status</th><th>Attention</th><th>Action</th></tr></thead><tbody>
@forelse($projects as $row)
  <tr><td class="name">{{ $row['project']->name }}<small>{{ $row['project']->project_code }}</small></td><td>{{ $row['project']->client?->name ?? '—' }}</td><td>{{ $row['current_phase'] ?? '—' }}</td><td><span class="dot-status {{ $row['project']->status === 'active' ? 'active' : 'zero' }}">{{ ucfirst(str_replace('_', ' ', $row['project']->status)) }}</span></td><td>@if($row['over_budget'])<span class="badge warn">Over budget</span>@elseif($row['no_budget'])<span class="badge warn">No budget set</span>@else<span class="badge good">On budget</span>@endif @if(count($projectWarnings[$row['project']->id] ?? []))<span class="badge warn" title="{{ collect($projectWarnings[$row['project']->id])->pluck('message')->join(' | ') }}">{{ count($projectWarnings[$row['project']->id]) }} warning(s)</span>@endif</td><td><a class="btn btn-ghost" href="{{ route('director.projects.show', [$row['project'], 'range' => $range['name']]) }}">View Project</a></td></tr>
@empty<tr><td colspan="6">No projects match this view.</td></tr>@endforelse
</tbody></table></div>
<style>
.director-actions{display:flex;gap:8px;flex-wrap:wrap}.date-filter{display:flex;gap:8px;align-items:end;flex-wrap:wrap}.date-filter .field{margin:0}.alert-link{text-decoration:none}.alert-link:hover{border-color:var(--primary)}.director-filter .row3{align-items:end}.filter-action{padding-bottom:13px;margin:0}.tablewrap td small{display:block;color:var(--ink-dim);font-size:10px;margin-top:3px}@media(max-width:700px){.director-filter .row3{grid-template-columns:1fr}.director-actions{width:100%}}
</style>
@endsection
