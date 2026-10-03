@extends('layouts.app')
@section('title', 'Estimator Dashboard · Buildiwick')
@section('content')
<div class="topline">
  <div><h2>Estimator Dashboard</h2><p>Client-facing estimates, including Buildiwick markup</p></div>
</div>

@if ($projects->isEmpty())
  <section class="panel empty-state">
    <h3>No projects are currently assigned to you.</h3>
    <p>Projects assigned to your Estimator/QS role will appear here.</p>
  </section>
@else
  <div class="tablewrap">
    <table class="estimator-project-table">
      <thead><tr><th>Project</th><th>Project Code</th><th>Client</th><th>Current Phase</th><th>Materials</th><th>Labour &amp; Subcontracts</th><th>Logistics &amp; Equipment</th><th>Preliminaries &amp; Site Overheads</th><th>Total Client Estimate</th><th>Estimate Status</th><th>Action</th></tr></thead>
      <tbody>
        @foreach ($projects as $row)
          <tr>
            <td class="name">{{ $row->project->name }}</td>
            <td class="code">{{ $row->project->project_code }}</td>
            <td>{{ $row->project->client?->name ?? '—' }}</td>
            <td>{{ $row->project->activePhase?->stage_label ?? '—' }}</td>
            @foreach (\App\Services\EstimatorEstimateService::CATEGORIES as $category => $label)
              <td class="amt">{{ \App\Support\Money::format($row->summary['categories'][$category]['client'] ?? '0.00') }}</td>
            @endforeach
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
@endsection