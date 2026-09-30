@extends('layouts.app')
@section('title', 'Project Setup · Buildiwick')
@section('content')
<div class="topline">
  <div>
    <h2>Project Setup</h2>
    <p>Confirmed projects registered in the Buildiwick operational system</p>
  </div>
  @can('create', App\Models\Project::class)
    <a class="btn btn-primary" href="{{ route('admin.projects.create') }}">+ New Project</a>
  @endcan
</div>

<div class="tablewrap">
  <table>
    <thead><tr><th>Project</th><th>Code</th><th>Client</th><th>Location</th><th>Current Phase</th><th>Status</th><th>Estimator/QS</th><th>Project Manager</th><th>Site Operator</th><th>Planned Start</th><th>Expected Completion</th><th>Actions</th></tr></thead>
    <tbody>
    @forelse ($projects as $project)
      @php
        $staff = [
          'estimator_qs' => $project->assignedUsers->first(fn ($user) => $user->hasRole('estimator_qs')),
          'project_manager' => $project->assignedUsers->first(fn ($user) => $user->hasRole('project_manager')),
          'site_operator' => $project->assignedUsers->first(fn ($user) => $user->hasRole('site_operator')),
        ];
      @endphp
      <tr>
        <td class="name">{{ $project->name }}</td>
        <td class="code">{{ $project->project_code }}</td>
        <td>{{ $project->client?->name ?? '—' }}</td>
        <td>{{ $project->location ?? '—' }}</td>
        <td>{{ $project->activePhase?->stage_label ?? '—' }}</td>
        <td><span class="dot-status {{ $project->status === 'active' ? 'active' : 'attn' }}">{{ str_replace('_', ' ', $project->status) }}</span></td>
        <td>{{ $staff['estimator_qs']?->name ?? '—' }}</td>
        <td>{{ $staff['project_manager']?->name ?? '—' }}</td>
        <td>{{ $staff['site_operator']?->name ?? '—' }}</td>
        <td>{{ $project->planned_start_date?->format('d M Y') ?? '—' }}</td>
        <td>{{ $project->expected_completion_date?->format('d M Y') ?? '—' }}</td>
        <td class="actions"><a href="{{ route('admin.projects.show', $project) }}">View Project</a><a href="{{ route('admin.projects.edit', $project) }}">Edit Setup</a></td>
      </tr>
    @empty
      <tr><td colspan="12" style="color:var(--ink-dim);font-style:italic;">No operational projects registered yet.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
<footer>{{ $projects->count() }} registered projects</footer>
@endsection