{{-- Data Entry sidebar: only the entry screen, and the chosen project while entering. --}}
@php
  $nav = fn (string $pattern) => request()->routeIs($pattern) ? 'active' : '';
  $entryProjects = app(\App\Services\LedgerAnalyticsService::class)->projectsFor(auth()->user())->count();
@endphp
<div class="label">Work</div>
@if($entryProjects > 1)<a class="navitem {{ $nav('entry.index') }}" href="{{ route('entry.index') }}">New Entry</a>@endif
@php($todayCount = \App\Http\Controllers\Web\DataEntryController::todayCount(auth()->user()))
<a class="navitem {{ $nav('entry.today') }}" href="{{ route('entry.today') }}">Entered Today @if($todayCount)<span class="badge" style="margin-left:auto">{{ $todayCount }}</span>@endif</a>
@if(($workspaceProject ?? null) && ! request()->routeIs('entry.index', 'entry.today'))
  <div class="project-workspace-card"><div class="label">Entering for</div><strong>{{ $workspaceProject->name }}</strong><small>{{ $workspaceProject->project_code }}</small></div>
  <a class="navitem {{ $nav('entry.form') }}" href="{{ route('entry.form', $workspaceProject) }}">Enter expenses</a>
  @if($entryProjects > 1)<a class="navitem" href="{{ route('entry.index') }}">Change project</a>@endif
@endif
