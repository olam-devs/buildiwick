@extends('layouts.app')
@section('title', 'Projects · Buildiwick')
@section('content')
@include('partials.ledger.styles')
@php($money = \App\Support\Money::class)
@php($totals = $portfolio['totals'])
<div class="lg-head">
  <div><h2>Projects</h2><p>Open a project to see its spending, record expenses and follow its budget.</p></div>
  <div class="lg-actions">
    <input type="search" id="projectSearch" class="lg-search" placeholder="Search name, ID or location…" value="{{ $filters['search'] ?? '' }}" autofocus>
    @if(auth()->user()->permits('export'))<a class="btn btn-ghost" href="{{ route('ledger.export') }}">Download Excel</a>@endif
    <select id="statusFilter" class="lg-select"><option value="">All statuses</option>@foreach(\App\Models\Lookup::ofType('project_status')->pluck('label','code') as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select>
  </div>
</div>

<div class="lg-cards">
  <div class="lg-card accent"><div class="k">Confirmed spend</div><div class="v">TSh {{ $money::format($totals['recorded']) }}</div><div class="s">{{ number_format($totals['lines']) }} expense lines</div></div>
  @can('confirm-payments')
    <a class="lg-card warn" href="{{ route('ledger.to-confirm') }}"><div class="k">Awaiting confirmation</div><div class="v">TSh {{ $money::format($totals['to_confirm']) }}</div><div class="s">{{ number_format($totals['to_confirm_count']) }} lines, not counted as spend yet</div></a>
  @else
    <div class="lg-card warn"><div class="k">Awaiting confirmation</div><div class="v">TSh {{ $money::format($totals['to_confirm']) }}</div><div class="s">{{ number_format($totals['to_confirm_count']) }} lines, not counted as spend yet</div></div>
  @endcan
  <div class="lg-card"><div class="k">Projects</div><div class="v">{{ $portfolio['rows']->count() }}</div><div class="s">{{ $totals['active_projects'] }} active</div></div>
</div>

<div class="lg-scroll" style="max-height:none">
  <table class="lg-table lg-cards-sm" id="projectTable">
    <thead><tr><th style="width:40px">#</th><th>Project</th><th>Status</th><th class="amt">Confirmed spend</th><th class="amt">Awaiting confirmation</th><th style="min-width:150px">Budget used</th><th>Last entry</th><th></th></tr></thead>
    <tbody>
    @forelse($portfolio['rows'] as $index => $row)
      @php($project = $row['project'])
      <tr data-search="{{ strtolower($project->name.' '.$project->project_code.' '.$project->location) }}" data-status="{{ $project->status }}" data-href="{{ route('ledger.overview', $project) }}">
        <td data-label="#" class="lg-num">{{ $index + 1 }}</td>
        <td data-label="Project"><b>{{ $project->name }}</b><span class="muted">{{ $project->project_code }}@if($project->location) · {{ $project->location }}@endif</span></td>
        <td data-label="Status"><span class="dot-status {{ $project->status === 'active' ? 'active' : 'zero' }}">{{ ucfirst(str_replace('_', ' ', $project->status)) }}</span></td>
        <td data-label="Confirmed spend" class="amt">{{ $money::format($row['recorded']) }}</td>
        <td data-label="Awaiting" class="amt">@if($row['to_confirm_count']){{ $money::format($row['to_confirm']) }}<span class="muted">{{ $row['to_confirm_count'] }} lines</span>@else — @endif</td>
        <td data-label="Budget used">@if($row['used'] !== null)<div class="lg-meter" style="margin-top:3px"><span class="{{ (float) $row['used'] > 100 ? 'over' : ((float) $row['used'] >= 85 ? 'near' : '') }}" style="width:{{ min(100, (float) $row['used']) }}%"></span></div><span class="muted">{{ $money::format($row['used']) }}% of TSh {{ $money::format($row['budget']) }}</span>@else<span class="muted">No budget yet</span>@endif</td>
        <td data-label="Last entry">{{ $row['last_entry'] ? \Carbon\Carbon::parse($row['last_entry'])->format('d M Y') : '—' }}</td>
        <td><a class="btn btn-ghost" href="{{ route('ledger.overview', $project) }}">Open</a></td>
      </tr>
    @empty
      <tr><td colspan="8" class="lg-empty">No projects to show.@if(! auth()->user()->seesAllProjects()) Ask the Director to give you access to your projects in User Management.@endif</td></tr>
    @endforelse
    <tr id="noMatch" hidden><td colspan="8" class="lg-empty">No project matches your search.</td></tr>
    </tbody>
  </table>
</div>
<script>
(() => {
  const rows = [...document.querySelectorAll('#projectTable tbody tr[data-search]')];
  const search = document.getElementById('projectSearch'), status = document.getElementById('statusFilter');
  const apply = () => {
    const q = search.value.trim().toLowerCase(), s = status.value; let n = 0;
    rows.forEach(r => { r.hidden = (q && !r.dataset.search.includes(q)) || (s && r.dataset.status !== s); if (!r.hidden) r.querySelector('.lg-num').textContent = ++n; });
    document.getElementById('noMatch').hidden = n > 0 || !rows.length;
  };
  search.addEventListener('input', apply); status.addEventListener('change', apply); apply();
  rows.forEach(r => { r.style.cursor = 'pointer'; r.addEventListener('click', e => { if (!e.target.closest('a,button')) location.href = r.dataset.href; }); });
})();
</script>
@endsection
