@extends('layouts.app')
@section('title', 'Expense Requests · Buildiwick')
@section('content')
<div class="topline"><div><h2>Expense Requests</h2><p>Requests are separate from actual payments and expenditure.</p></div><a class="btn btn-primary" href="{{ route('site.index') }}">Projects</a></div>
@if($requests->isEmpty())
  <section class="panel empty-state"><h3>No expense requests yet.</h3><p>Open an assigned project to submit a site requirement.</p></section>
@else
  <div class="tablewrap"><table style="min-width:900px;"><thead><tr><th>Request Code</th><th>Project</th><th>Phase</th><th>Date</th><th>Category</th><th>Item / Description</th><th>Quantity</th><th>Unit</th><th>Approval</th><th>Action</th></tr></thead><tbody>
  @foreach($requests as $request)<tr><td><a href="{{ route('site.requests.show', [$request->project, $request]) }}">{{ $request->request_code }}</a></td><td>{{ $request->project->name }}</td><td>{{ $request->phase->stage_label }}</td><td>{{ $request->requested_at?->format('d M Y') }}</td><td>{{ $request->main_category }}</td><td>{{ $request->item_description }}</td><td class="amt">{{ $request->quantity ?? '—' }}</td><td>{{ $request->unit ?? '—' }}</td><td><span class="status-pill approval-{{ $request->status }}">{{ ucwords(str_replace('_', ' ', $request->status)) }}</span></td><td><a href="{{ route('site.requests.show', [$request->project, $request]) }}">View</a></td></tr>@endforeach
  </tbody></table></div>
@endif
@endsection