@extends('layouts.app')
@section('title', 'Labour & Subcontracts · Project Manager')
@section('content')
<div class="topline"><div><h2>Labour &amp; Subcontracts</h2><p>Manage labour or subcontract terms here. Site and PM requests can then reference the agreement; Accounts records payment after PM approval.</p></div><a class="btn btn-ghost" href="{{ route('pm.requests.index', ['category' => 'labour_subcontracts']) }}">Labour Requests</a></div>
<section class="panel form-section"><h3>Create Agreement</h3><div class="estimate-actions">@foreach($projects as $project)<a class="btn btn-primary" href="{{ route('pm.labour.create', $project) }}">{{ $project->name }}</a>@endforeach</div></section>
<div class="tablewrap"><table><thead><tr><th>Agreement</th><th>Project</th><th>Worker / Fundi / Subcontractor</th><th>Type</th><th>Scope</th><th>Agreed Value</th><th>PM-Approved Requests</th><th>Actual Paid</th><th>Outstanding</th><th>Status</th><th>Raise Request</th></tr></thead><tbody>
@forelse($agreements as $agreement)
@php($approved = \App\Support\Money::sum($agreement->expenseRequests->where('status','approved')->map(fn($item) => $item->approved_payable_amount)))
@php($paid = \App\Support\Money::decimal((string)$agreement->paidToDate())->__toString())
<tr><td>{{ $agreement->agreement_code }}</td><td>{{ $agreement->project->name }}</td><td>{{ $agreement->fundi_subcontractor }}</td><td>{{ ucwords(str_replace('_',' ',$agreement->labour_type ?? '—')) }}</td><td>{{ $agreement->scope_of_work ?: '—' }}</td><td class="amt">{{ \App\Support\Money::format($agreement->agreed_value) }}</td><td class="amt">{{ \App\Support\Money::format($approved) }}</td><td class="amt">{{ \App\Support\Money::format($paid) }}</td><td class="amt">{{ \App\Support\Money::format(\App\Support\Money::difference($agreement->agreed_value, $paid)) }}</td><td>{{ ucfirst($agreement->status) }}</td><td><a href="{{ route('pm.requests.create', [$agreement->project, 'category'=>'labour_subcontracts', 'agreement_id'=>$agreement->id]) }}">New request</a></td></tr>
@empty<tr><td colspan="11">No labour or subcontract agreements yet.</td></tr>@endforelse
</tbody></table></div>
@endsection
