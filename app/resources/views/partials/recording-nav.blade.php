{{-- Recording-mode sidebar: company menu outside a project, project menu inside one. --}}
@php
  $user = auth()->user();
  $isDirector = $user->hasRole('admin');
  $canConfirm = $user->can('confirm-payments');
  $nav = fn (string $pattern) => request()->routeIs($pattern) ? 'active' : '';
@endphp
@if($workspaceProject ?? null)
  <div class="project-workspace-card"><div class="label">Project</div><strong>{{ $workspaceProject->name }}</strong><small>{{ $workspaceProject->project_code }}</small></div>
  <a class="navitem {{ $nav('ledger.overview') }}" href="{{ route('ledger.overview', $workspaceProject) }}">Overview</a>
  @php($confirmView = request()->routeIs('ledger.expenses') && request('status') === 'to_confirm')
  <a class="navitem {{ request()->routeIs('ledger.expenses') && ! $confirmView ? 'active' : '' }}" href="{{ route('ledger.expenses', $workspaceProject) }}">Expenditure</a>
  @can('confirm-payments', $workspaceProject)
    @php($waiting = \App\Models\ExpenseRequest::query()->where('project_id', $workspaceProject->id)->where('status', 'approved')->where('total_amount', '>', 0)->doesntHave('payments')->count())
    <a class="navitem {{ $confirmView ? 'active' : '' }}" href="{{ route('ledger.expenses', [$workspaceProject, 'status' => 'to_confirm', 'sort' => 'oldest']) }}">To Confirm @if($waiting)<span class="badge warn" style="margin-left:auto">{{ $waiting }}</span>@endif</a>
  @endcan
  @can('record-expense', $workspaceProject)
    <a class="navitem {{ $nav('ledger.expenses.create') }}" href="{{ route('ledger.expenses.create', $workspaceProject) }}">Record Expense</a>
  @endcan
  <a class="navitem {{ $nav('ledger.structure') }}" href="{{ route('ledger.structure', $workspaceProject) }}">Phases &amp; Budget</a>
  <a class="navitem {{ $nav('ledger.workers') }}" href="{{ route('ledger.workers', $workspaceProject) }}">Workers</a>
  @if($isDirector)
    <a class="navitem {{ $nav('director.projects.warnings') }}" href="{{ route('director.projects.warnings', $workspaceProject) }}">Warnings &amp; KPIs</a>
    <a class="navitem {{ $nav('admin.projects.*') }}" href="{{ route('admin.projects.show', $workspaceProject) }}">Project Settings</a>
  @endif
  <a class="navitem close-workspace" href="{{ $isDirector ? route('director.index') : route('ledger.index') }}">Close Project</a>
@else
  <div class="label">{{ $isDirector ? 'Company' : 'Work' }}</div>
  @if($isDirector)
    <a class="navitem {{ $nav('director.index') }}" href="{{ route('director.index') }}">Dashboard</a>
  @endif
  <a class="navitem {{ $nav('ledger.index') }}" href="{{ route('ledger.index') }}">Projects</a>
  @if($canConfirm)
    <a class="navitem {{ $nav('ledger.to-confirm') }}" href="{{ route('ledger.to-confirm') }}">To Confirm</a>
  @endif
  @if($isDirector)
    <div class="label">Company Targets</div>
    <a class="navitem {{ $nav('director.kpis.*') }}" href="{{ route('director.kpis.index') }}">Company KPI Targets</a>
    <a class="navitem {{ $nav('director.warning-rules.*') }}" href="{{ route('director.warning-rules.index') }}">Default Warning Rules</a>
    <div class="label">Administration</div>
    <a class="navitem {{ $nav('admin.projects.*') }}" href="{{ route('admin.projects.index') }}">Project Setup</a>
    <a class="navitem {{ $nav('admin.users.*') }}" href="{{ route('admin.users.index') }}">User Management</a>
  @endif
@endif
