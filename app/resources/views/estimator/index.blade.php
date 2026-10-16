@extends('layouts.app')
@section('title', 'Estimator Dashboard · Buildiwick')
@section('mainClass', 'project-list-main')
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
    <table class="estimator-project-table responsive-project-table">
      <thead><tr><th>Project</th><th>Client</th><th>Current Phase</th><th>Total Client Estimate</th><th>Estimate Status</th></tr></thead>
      <tbody>
        @foreach ($projects as $row)
          <tr class="clickable-project-row" tabindex="0" data-href="{{ route('estimator.projects.show', $row->project) }}">
            <td class="name"><a href="{{ route('estimator.projects.show', $row->project) }}">{{ $row->project->name }}</a><small>{{ $row->project->project_code }}</small></td>
            <td>{{ $row->project->client?->name ?? '—' }}</td>
            <td>{{ $row->project->activePhase?->stage_label ?? '—' }}</td>
            <td class="amt">{{ \App\Support\Money::format($row->summary['clientTotal'] ?? '0.00') }}</td>
            <td><span class="dot-status {{ $row->status === 'issued' ? 'active' : ($row->status === 'draft' ? 'attn' : 'zero') }}">{{ $row->status === 'not_started' ? 'Not started' : ucfirst($row->status) }}</span></td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  <footer>{{ $projects->count() }} assigned projects · all estimate amounts are client-facing values after markup</footer>
@endif
<style>.estimator-project-table td small{display:block;color:var(--ink-dim);font-size:10px;margin-top:3px}.clickable-project-row{cursor:pointer}.clickable-project-row:hover{background:var(--primary-soft)}.clickable-project-row:focus{outline:2px solid var(--primary);outline-offset:-2px}.clickable-project-row .name a{text-decoration:none;font-weight:700}</style>
<script>(()=>{document.querySelectorAll('.clickable-project-row').forEach(row=>{const open=()=>location.href=row.dataset.href;row.addEventListener('click',event=>{if(!event.target.closest('a,button,input,select'))open()});row.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();open()}})})})();</script>
@endsection
