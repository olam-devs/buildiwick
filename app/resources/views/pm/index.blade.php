@extends('layouts.app')
@section('title', 'Project Manager · Buildiwick')
@section('content')
<div class="topline"><div><h2>Project Manager Dashboard</h2><p>Internal operational budgets compared with Site Operator requests. Requests are not expenditure.</p></div></div>
@if($projects->isEmpty())
  <section class="panel empty-state"><h3>No projects are currently assigned to you.</h3><p>Your assigned projects will appear here when they are added to your project team.</p></section>
@else
  <div class="tablewrap"><table class="pm-project-table"><thead><tr><th>Project</th><th>Code</th><th>Current Phase</th><th>Project Status</th><th>Internal Budget</th><th>Approved Commitments</th><th>Remaining Budget</th><th>Budget Utilization</th><th>Commercialized Requests</th><th>Awaiting Commercial Details</th><th>Ready for Decision</th><th>Approved Awaiting Accounts</th><th>Action</th></tr></thead><tbody>
  @foreach($projects as $row)
    @php($comparison=$row->comparison)
    <tr>
      <td class="name">{{ $row->project->name }}</td><td class="code">{{ $row->project->project_code }}</td><td>{{ $row->project->activePhase?->stage_label ?? '—' }}</td><td>{{ ucfirst(str_replace('_',' ',$row->project->status)) }}</td>
      <td class="amt">{{ \App\Support\Money::format($comparison['budget']) }}</td><td class="amt">{{ \App\Support\Money::format($comparison['requested']) }}</td>
      <td class="amt {{ $comparison['over_budget'] ? 'over-budget' : '' }}">{{ \App\Support\Money::format($comparison['remaining']) }} @if($comparison['over_budget'])<small>Over by {{ \App\Support\Money::format($comparison['over_budget_amount']) }}</small>@endif</td>
      <td class="amt">{{ $comparison['utilization'] === null ? 'No budget set' : \App\Support\Money::format($comparison['utilization']).'%' }}</td>
      <td>{{ $row->commercialized_count }}</td><td>{{ $row->awaiting_commercial_count }}</td><td>{{ $row->ready_count }}</td><td>{{ $row->approved_awaiting_accounts }}</td>
      <td><a class="btn btn-ghost" href="{{ route('pm.projects.show',$row->project) }}">Open Budget</a></td>
    </tr>
  @endforeach
  </tbody></table></div>
  <footer>{{ $projects->count() }} assigned projects · Remaining Budget is Internal Budget minus approved commercial commitments; payments are a later Accounts realization.</footer>
@endif
@endsection