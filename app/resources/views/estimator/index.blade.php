@extends('layouts.app')
@section('title', 'Estimator Dashboard · Buildiwick')
@section('content')
<div class="topline">
  <div><h2>Estimator Dashboard</h2><p>Client-facing estimates, including Buildiwick markup</p></div>
</div>

@if ($projects->isEmpty())
  <section class="panel empty-state">
    <h3>No projects are currently assigned to you.</h3>
    <p>Ask the Director to open <strong>Project Setup</strong> and assign you as the Estimator/QS. The project will then appear here automatically.</p>
  </section>
@else
  <div class="tablewrap">
    <table class="estimator-project-table">
      <thead><tr><th>Project</th><th>Client</th><th>Current Phase</th><th>Total Client Estimate</th><th>Estimate Status</th><th>Action</th></tr></thead>
      <tbody>
        @foreach ($projects as $row)
          <tr>
            <td class="name">{{ $row->project->name }}<small>{{ $row->project->project_code }}</small></td>
            <td>{{ $row->project->client?->name ?? '—' }}</td>
            <td>{{ $row->project->activePhase?->stage_label ?? '—' }}</td>
            <td class="amt">{{ \App\Support\Money::format($row->summary['clientTotal'] ?? '0.00') }}</td>
            <td><span class="dot-status {{ $row->status === 'issued' ? 'active' : ($row->status === 'draft' ? 'attn' : 'zero') }}">{{ $row->status === 'not_started' ? 'Not started' : ucfirst($row->status) }}</span></td>
            <td><a class="btn btn-ghost" href="{{ route('estimator.projects.show', $row->project) }}">Open Estimate</a></td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  <footer>{{ $projects->count() }} assigned projects · all estimate amounts are client-facing values after markup</footer>
@endif
<style>.estimator-project-table td small{display:block;color:var(--ink-dim);font-size:10px;margin-top:3px}</style>
@endsection
