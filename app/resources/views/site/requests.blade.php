@extends('layouts.app')
@section('title', 'Expense Requests · Buildiwick')
@section('content')
<div class="topline"><div><h2>Expense Requests</h2><p>Requests are separate from actual payments and expenditure.</p></div><a class="btn btn-primary" href="{{ route('site.index') }}">Projects</a></div>
@if($requestBatches->isEmpty())
  <section class="panel empty-state"><h3>No expense requests yet.</h3><p>Open an assigned project to submit a site requirement.</p></section>
@else
  <p class="form-context">Each row is one complete request. Open it to see every item in that request.</p>
  <div class="tablewrap"><table style="min-width:900px;"><thead><tr><th>Request</th><th>Project</th><th>Phase</th><th>Date</th><th>Items</th><th>Supplier Groups</th><th>Approval</th><th>Action</th></tr></thead><tbody>
  @foreach($requestBatches as $batch)@php($request=$batch->anchor)<tr><td><a href="{{ route('site.requests.show', [$request->project, $request]) }}">{{ $batch->code }}</a></td><td>{{ $request->project->name }}</td><td>{{ $request->phase?->stage_label ?? 'Multiple / not set' }}</td><td>{{ $request->requested_at?->format('d M Y') }}</td><td>{{ $batch->item_count }}</td><td>{{ $batch->supplier_count ?: 'Awaiting PM grouping' }}</td><td>@foreach($batch->statuses as $status)<span class="status-pill approval-{{ $status }}">{{ ucwords(str_replace('_', ' ', $status)) }}</span> @endforeach</td><td><a href="{{ route('site.requests.show', [$request->project, $request]) }}">Open Request</a></td></tr>@endforeach
  </tbody></table></div>
@endif
@endsection
