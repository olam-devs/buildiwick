@extends('layouts.app')
@section('title', 'Site Operator · Buildiwick')
@section('content')
<div class="topline"><div><h2>Site Operator Dashboard</h2><p>Report what the site needs. Project Managers handle pricing and procurement.</p></div><a class="btn btn-ghost" href="{{ route('site.requests.index') }}">Expense Requests</a></div>

@if($projects->isEmpty())
  <section class="panel empty-state"><h3>No projects are currently assigned to you.</h3><p>Your assigned projects will appear here.</p></section>
@else
  <div class="tablewrap"><table class="site-project-table">
    <thead><tr><th>Project</th><th>Current Phase</th><th>Materials</th><th>Labour &amp; Subcontracts</th><th>Logistics &amp; Equipment</th><th>Preliminaries &amp; Overheads</th><th>Total</th><th>Pending</th><th>Awaiting PM Review</th><th>Status</th></tr></thead>
    <tbody>
      @foreach($projects as $row)
        @php($hasRequests = $row->summary['total_requests'] > 0)
        <tr class="clickable-project-row" tabindex="0" data-href="{{ route('site.projects.show', $row->project) }}">
          <td class="name"><a href="{{ route('site.projects.show', $row->project) }}">{{ $row->project->name }}</a><small>{{ $row->project->project_code }}</small></td>
          <td>{{ $row->phase_label }}</td>
          @foreach($row->summary['categories'] as $category)<td class="amt">{{ $category['requests'] }}</td>@endforeach
          <td class="amt">{{ $row->summary['total_requests'] }}</td>
          <td>{{ $row->summary['pending_count'] }}</td>
          <td>{{ $row->summary['awaiting_pricing_count'] }}</td>
          <td><span class="dot-status {{ $hasRequests ? 'active' : 'zero' }}">{{ $hasRequests ? 'Requests submitted' : 'No requests' }}</span></td>
        </tr>
      @endforeach
    </tbody>
  </table></div>
  <footer>{{ $projects->count() }} assigned projects · quantities are operational needs; pricing is handled by the Project Manager</footer>
@endif
<style>.clickable-project-row{cursor:pointer}.clickable-project-row:hover{background:var(--primary-soft)}.clickable-project-row:focus{outline:2px solid var(--primary);outline-offset:-2px}.clickable-project-row .name a{text-decoration:none;font-weight:700}.clickable-project-row .name small{display:block;color:var(--ink-dim);margin-top:3px}</style>
<script>(()=>{document.querySelectorAll('.clickable-project-row').forEach(row=>{const open=()=>location.href=row.dataset.href;row.addEventListener('click',event=>{if(!event.target.closest('a,button,input,select'))open()});row.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();open()}})})})();</script>
@endsection
