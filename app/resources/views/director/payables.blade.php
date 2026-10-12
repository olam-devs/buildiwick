@extends('layouts.app')
@section('title', 'Approved Payables · Director')
@section('content')
@php($money = '\App\Support\Money')
<div class="topline"><div><h2>Outstanding Approved Payables</h2><p>Current position from PM-approved expense requests, net of actual payment events.</p></div><a class="btn btn-ghost" href="{{ route('director.index') }}">Executive Dashboard</a></div>
<form method="GET" class="panel form-section"><div class="row2"><div class="field"><label for="project_id">Project</label><select name="project_id" id="project_id"><option value="">All Projects</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected((string) request('project_id') === (string) $project->id)>{{ $project->name }}</option>@endforeach</select></div><div style="align-self:end"><button class="btn btn-ghost" type="submit">Filter Projects</button></div></div></form>
<div class="tablewrap"><table><thead><tr><th>Request</th><th>Project</th><th>Phase</th><th>Category</th><th>Item</th><th>Approved</th><th>Paid</th><th>Outstanding</th><th>Action</th></tr></thead><tbody>
@forelse($payables as $item)
  <tr><td>{{ $item['request']->request_code }}</td><td>{{ $item['project']->name }}</td><td>{{ $item['request']->phase?->stage_code ? ucfirst(str_replace('_', ' ', $item['request']->phase->stage_code)) : '—' }}</td><td>{{ \App\Services\ProjectManagerBudgetService::CATEGORIES[$item['request']->main_category] ?? $item['request']->main_category }}</td><td>{{ $item['request']->item_description }}</td><td class="amt">{{ $money::format($item['approved']) }}</td><td class="amt">{{ $money::format($item['paid']) }}</td><td class="amt">{{ $money::format($item['outstanding']) }}</td><td><a href="{{ route('director.projects.show', $item['project']) }}">Project analytics</a></td></tr>
@empty<tr><td colspan="9">There are no outstanding approved payables.</td></tr>@endforelse
</tbody></table></div>
<footer>Approved commitments are not actual expenditure. Actual expenditure is recorded only by ExpensePayment events. Payment destinations and account details are not shown.</footer>
@endsection
