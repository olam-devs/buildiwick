@extends('layouts.app')
@section('title', 'Site Operator · Buildiwick')
@section('content')
<div class="topline"><div><h2>Site Operator Dashboard</h2><p>Report what the site needs. Project Managers handle pricing and procurement.</p></div><a class="btn btn-ghost" href="{{ route('site.requests.index') }}">Expense Requests</a></div>

@if($projects->isEmpty())
  <section class="panel empty-state"><h3>No projects are currently assigned to you.</h3><p>Your assigned projects will appear here.</p></section>
@else
  <div class="tablewrap"><table class="site-project-table">
    <thead><tr><th>Project</th><th>Code</th><th>Current Phase</th><th>Materials Requests</th><th>Labour &amp; Subcontracts Requests</th><th>Logistics &amp; Equipment Requests</th><th>Preliminaries &amp; Site Overheads Requests</th><th>Total Requests</th><th>Pending Requests</th><th>Awaiting PM Pricing/Review</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
      @foreach($projects as $row)
        @php($hasRequests = $row->summary['total_requests'] > 0)
        <tr>
          <td class="name">{{ $row->project->name }}</td><td class="code">{{ $row->project->project_code }}</td>
          <td>{{ $row->phase_label }}</td>
          @foreach($row->summary['categories'] as $category)<td class="amt">{{ $category['requests'] }}</td>@endforeach
          <td class="amt">{{ $row->summary['total_requests'] }}</td>
          <td>{{ $row->summary['pending_count'] }}</td>
          <td>{{ $row->summary['awaiting_pricing_count'] }}</td>
          <td><span class="dot-status {{ $hasRequests ? 'active' : 'zero' }}">{{ $hasRequests ? 'Requests submitted' : 'No requests' }}</span></td>
          <td><a class="btn btn-ghost" href="{{ route('site.projects.show', $row->project) }}">Open Project</a></td>
        </tr>
      @endforeach
    </tbody>
  </table></div>
  <footer>{{ $projects->count() }} assigned projects · quantities are operational needs; pricing is handled by the Project Manager</footer>
@endif
@endsection