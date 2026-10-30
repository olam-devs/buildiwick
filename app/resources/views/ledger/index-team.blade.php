@extends('layouts.app')
@section('title', 'Projects · Buildiwick')
@section('content')
@include('partials.ledger.styles')
@php($money = \App\Support\Money::class)
<div class="lg-head">
  <div><h2>Projects</h2><p>Open a project to record expenses and follow what is waiting to be confirmed.</p></div>
  <div class="lg-actions"><input type="search" id="projectSearch" class="lg-search" placeholder="Search name, ID or location…" value="{{ $filters['search'] ?? '' }}"></div>
</div>

<div class="lg-scroll" style="max-height:none">
  <table class="lg-table lg-cards-sm" id="projectTable">
    <thead><tr><th style="width:40px">#</th><th>Project</th><th>Status</th><th class="amt">To confirm</th><th>Last entry</th><th></th></tr></thead>
    <tbody>
    @forelse($rows as $index => $row)
      @php($project = $row['project'])
      <tr data-search="{{ strtolower($project->name.' '.$project->project_code.' '.$project->location) }}" data-href="{{ route('ledger.expenses', $project) }}">
        <td data-label="#" class="lg-num">{{ $index + 1 }}</td>
        <td data-label="Project"><b>{{ $project->name }}</b><span class="muted">{{ $project->project_code }}@if($project->location) · {{ $project->location }}@endif</span></td>
        <td data-label="Status"><span class="dot-status {{ $project->status === 'active' ? 'active' : 'zero' }}">{{ ucfirst(str_replace('_', ' ', $project->status)) }}</span></td>
        <td data-label="To confirm" class="amt">@if($row['to_confirm_count'])<a href="{{ route('ledger.expenses', [$project, 'status' => 'to_confirm', 'sort' => 'oldest']) }}" style="color:inherit"><b>{{ $money::format($row['to_confirm']) }}</b></a><span class="muted">{{ $row['to_confirm_count'] }} {{ \Illuminate\Support\Str::plural('line', $row['to_confirm_count']) }}</span>@else<span class="muted">Nothing waiting</span>@endif</td>
        <td data-label="Last entry">{{ $row['last_entry'] ? \Carbon\Carbon::parse($row['last_entry'])->format('d M Y') : '—' }}</td>
        <td class="lg-btn-cell"><div class="lg-btns">
          @can('record-expense', $project)<a class="lg-btn" href="{{ route('ledger.expenses.create', $project) }}">+ Record</a>@endcan
          <a class="lg-btn" href="{{ route('ledger.expenses', $project) }}">Open</a>
        </div></td>
      </tr>
    @empty
      <tr><td colspan="6" class="lg-empty">No projects to show. Ask the Director to give you access to your projects in User Management.</td></tr>
    @endforelse
    <tr id="noMatch" hidden><td colspan="6" class="lg-empty">No project matches your search.</td></tr>
    </tbody>
  </table>
</div>
@include('partials.ledger.list-script', ['table' => 'projectTable', 'search' => 'projectSearch'])
<script>(() => { const s = document.getElementById('projectSearch'), rows = [...document.querySelectorAll('#projectTable tr[data-search]')];
  s.addEventListener('input', () => document.getElementById('noMatch').hidden = !rows.length || rows.some(r => !r.hidden)); })();</script>
@endsection
