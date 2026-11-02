{{-- Data Entry sidebar: only the entry screen, and the chosen project while entering. --}}
@php($nav = fn (string $pattern) => request()->routeIs($pattern) ? 'active' : '')
<div class="label">Work</div>
<a class="navitem {{ $nav('entry.index') }}" href="{{ route('entry.index') }}">New Entry</a>
@if(($workspaceProject ?? null) && ! request()->routeIs('entry.index'))
  <div class="project-workspace-card"><div class="label">Entering for</div><strong>{{ $workspaceProject->name }}</strong><small>{{ $workspaceProject->project_code }}</small></div>
  <a class="navitem {{ $nav('entry.form') }}" href="{{ route('entry.form', $workspaceProject) }}">Enter expenses</a>
  <a class="navitem" href="{{ route('entry.index') }}">Change project</a>
@endif
