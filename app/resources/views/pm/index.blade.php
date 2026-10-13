@extends('layouts.app')
@section('title', 'Project Manager · Buildiwick')
@section('content')
<div class="topline"><div><h2>Project Manager Dashboard</h2><p>See what needs attention now. Open a project for the full budget and usage breakdown.</p></div><a class="btn btn-primary" href="{{ route('pm.requests.index') }}">Open Request Queue</a></div>
@if($projects->isEmpty())
  <section class="panel empty-state"><h3>No projects are currently assigned to you.</h3><p>Your assigned projects will appear here when they are added to your project team.</p></section>
@else
  <section class="panel form-section workflow-guide"><strong>Simple working order</strong><span>1. Set the internal budget</span><span>2. Price and approve requests</span><span>3. Accounts records payment</span></section>
  <div class="tablewrap"><table class="pm-project-table"><thead><tr><th>Project</th><th>Current Phase</th><th>Internal Budget</th><th>Remaining</th><th>Needs Pricing</th><th>Ready to Approve</th><th>With Accounts</th><th>Action</th></tr></thead><tbody>
  @foreach($projects as $row)
    @php($comparison=$row->comparison)
    <tr>
      <td class="name">{{ $row->project->name }}<small>{{ $row->project->project_code }}</small></td><td>{{ $row->project->activePhase?->stage_label ?? '—' }}</td>
      <td class="amt">{{ \App\Support\Money::compare($comparison['budget'], '0') === 0 ? 'No budget set' : \App\Support\Money::format($comparison['budget']) }}</td>
      <td class="amt {{ $comparison['over_budget'] ? 'over-budget' : '' }}">{{ \App\Support\Money::format($comparison['remaining']) }} @if($comparison['over_budget'])<small>Over by {{ \App\Support\Money::format($comparison['over_budget_amount']) }}</small>@endif</td>
      <td>{{ $row->awaiting_commercial_count }}</td><td>{{ $row->ready_count }}</td><td>{{ $row->approved_awaiting_accounts }}</td>
      <td><a class="btn btn-ghost" href="{{ route('pm.projects.show',$row->project) }}">Open Project</a></td>
    </tr>
  @endforeach
  </tbody></table></div>
  <footer>{{ $projects->count() }} assigned projects · Remaining is the internal budget minus PM-approved commitments.</footer>
@endif
<style>.workflow-guide{display:flex;gap:14px;align-items:center;flex-wrap:wrap}.workflow-guide span{color:var(--ink-soft);font-size:12px}.pm-project-table td small{display:block;color:var(--ink-dim);font-size:10px;margin-top:3px}</style>
@endsection
