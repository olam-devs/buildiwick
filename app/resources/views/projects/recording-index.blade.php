@extends('layouts.app')
@section('title', 'Project Setup · Buildiwick')
@section('content')
@include('partials.ledger.styles')
<div class="lg-head">
  <div><h2>Project Setup</h2><p>Register projects, assign Project Managers and set each project's status.</p></div>
  <div class="lg-actions"><a class="btn btn-primary" href="{{ route('admin.projects.create') }}">+ New Project</a></div>
</div>
<div class="lg-scroll">
  <table class="lg-table">
    <thead><tr><th>Project ID</th><th>Project</th><th>Location</th><th>Dates</th><th>Status</th><th>Project Manager</th><th class="amt">Phases</th><th></th></tr></thead>
    <tbody>
    @forelse($projects as $project)
      <tr>
        <td class="code">{{ $project->project_code }}</td>
        <td><b>{{ $project->name }}</b></td>
        <td>{{ $project->location ?: '—' }}</td>
        <td style="white-space:nowrap">{{ $project->planned_start_date?->format('d M Y') ?? '—' }} – {{ $project->expected_completion_date?->format('d M Y') ?? '—' }}</td>
        <td><span class="dot-status {{ $project->status === 'active' ? 'active' : 'zero' }}">{{ ucfirst(str_replace('_', ' ', $project->status)) }}</span></td>
        <td>{{ $project->assignedUsers->filter(fn ($user) => $user->role?->name === 'project_manager')->pluck('name')->join(', ') ?: '—' }}</td>
        <td class="amt">{{ $project->phases_count }}</td>
        <td><div class="lg-row-actions"><a class="lg-link" href="{{ route('admin.projects.show', $project) }}">Settings</a><a class="lg-link" href="{{ route('ledger.overview', $project) }}">Open</a></div></td>
      </tr>
    @empty
      <tr><td colspan="8" class="lg-empty">No projects yet.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
@endsection
