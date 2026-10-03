@extends('layouts.app')
@section('title', 'Accountant Projects · Buildiwick')
@section('content')
<div class="topline"><div><h2>Project Outgoing Payments</h2><p>Approved commitments, actual paid events, and outstanding balances.</p></div></div>
<div class="tablewrap"><table><thead><tr><th>Project</th><th>Code</th><th>Approved Requests</th><th>Action</th></tr></thead><tbody>@forelse($projects as $project)<tr><td>{{ $project->name }}</td><td>{{ $project->project_code }}</td><td>{{ $project->approved_request_count }}</td><td><a href="{{ route('accountant.projects.show', $project) }}">Open financial view</a></td></tr>@empty<tr><td colspan="4">No projects available.</td></tr>@endforelse</tbody></table></div>
@endsection