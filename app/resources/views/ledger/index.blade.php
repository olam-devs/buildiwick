@extends('layouts.app')
@section('title', 'Projects · Buildiwick')
@section('content')
@include('partials.ledger.styles')
@php($money = \App\Support\Money::class)
@php($totals = $portfolio['totals'])
<div class="lg-head">
  <div><h2>Projects</h2><p>Open a project to see its spending, record expenses and follow its budget.</p></div>
  <form method="GET" class="lg-actions">
    <input name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Search name, code, location" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px;min-width:230px">
    <select name="status" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px"><option value="">All statuses</option>@foreach(\App\Models\Lookup::ofType('project_status')->pluck('label','code') as $code => $label)<option value="{{ $code }}" @selected(($filters['status'] ?? '') === $code)>{{ $label }}</option>@endforeach</select>
    <button class="btn btn-primary" type="submit">Filter</button>
  </form>
</div>

<div class="lg-cards">
  <div class="lg-card accent"><div class="k">Total recorded</div><div class="v">TSh {{ $money::format($totals['recorded']) }}</div><div class="s">{{ number_format($totals['lines']) }} expense lines</div></div>
  <div class="lg-card good"><div class="k">Paid</div><div class="v">TSh {{ $money::format($totals['paid']) }}</div><div class="s">Confirmed by Accounts</div></div>
  @if(auth()->user()->hasRole('admin') || auth()->user()->hasRole('accounts') || auth()->user()->hasRole('accountant'))
    <a class="lg-card warn" href="{{ route('ledger.to-confirm') }}"><div class="k">To confirm</div><div class="v">TSh {{ $money::format($totals['to_confirm']) }}</div><div class="s">{{ number_format($totals['to_confirm_count']) }} lines waiting for Accounts</div></a>
  @else
    <div class="lg-card warn"><div class="k">To confirm</div><div class="v">TSh {{ $money::format($totals['to_confirm']) }}</div><div class="s">{{ number_format($totals['to_confirm_count']) }} lines waiting for Accounts</div></div>
  @endif
  <div class="lg-card"><div class="k">Projects</div><div class="v">{{ $portfolio['rows']->count() }}</div><div class="s">{{ $totals['active_projects'] }} active</div></div>
</div>

<div class="lg-scroll">
  <table class="lg-table">
    <thead><tr><th>Project</th><th>Status</th><th class="amt">Recorded</th><th class="amt">Paid</th><th class="amt">To confirm</th><th style="min-width:150px">Budget used</th><th>Last entry</th><th></th></tr></thead>
    <tbody>
    @forelse($portfolio['rows'] as $row)
      @php($project = $row['project'])
      <tr>
        <td><a href="{{ route('ledger.overview', $project) }}" style="font-weight:600;color:var(--ink);text-decoration:none">{{ $project->name }}</a><span class="muted">{{ $project->project_code }}@if($project->location) · {{ $project->location }}@endif</span></td>
        <td><span class="dot-status {{ $project->status === 'active' ? 'active' : 'zero' }}">{{ ucfirst(str_replace('_', ' ', $project->status)) }}</span></td>
        <td class="amt">{{ $money::format($row['recorded']) }}</td>
        <td class="amt">{{ $money::format($row['paid']) }}</td>
        <td class="amt">@if($row['to_confirm_count']){{ $money::format($row['to_confirm']) }}<span class="muted">{{ $row['to_confirm_count'] }} lines</span>@else — @endif</td>
        <td>@if($row['used'] !== null)<div class="lg-meter" style="margin-top:3px"><span class="{{ (float) $row['used'] > 100 ? 'over' : ((float) $row['used'] >= 85 ? 'near' : '') }}" style="width:{{ min(100, (float) $row['used']) }}%"></span></div><span class="muted">{{ $money::format($row['used']) }}% of TSh {{ $money::format($row['budget']) }}</span>@else<span class="muted">No budget yet</span>@endif</td>
        <td>{{ $row['last_entry'] ? \Carbon\Carbon::parse($row['last_entry'])->format('d M Y') : '—' }}</td>
        <td><a class="btn btn-ghost" href="{{ route('ledger.overview', $project) }}">Open</a></td>
      </tr>
    @empty
      <tr><td colspan="8" class="lg-empty">No projects to show.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
@endsection
