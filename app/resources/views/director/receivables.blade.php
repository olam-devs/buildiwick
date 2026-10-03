@extends('layouts.app')
@section('title', 'Receivables · Director')
@section('content')
@php($money = '\App\Support\Money')
<div class="topline"><div><h2>Outstanding Receivables</h2><p>Current position from issued invoices only. Draft, void, and fully paid invoices are excluded.</p></div><a class="btn btn-ghost" href="{{ route('director.index') }}">Executive Dashboard</a></div>
<form method="GET" class="panel form-section"><div class="row2"><div class="field"><label for="project_id">Project</label><select name="project_id" id="project_id"><option value="">All Projects</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected((string) request('project_id') === (string) $project->id)>{{ $project->name }}</option>@endforeach</select></div><div style="align-self:end"><button class="btn btn-ghost" type="submit">Filter Projects</button></div></div></form>
<div class="tablewrap"><table><thead><tr><th>Invoice</th><th>Project</th><th>Client</th><th>Issued</th><th>Due</th><th>Invoice Total</th><th>Received</th><th>Outstanding</th><th>Attention</th><th>Action</th></tr></thead><tbody>
@forelse($receivables as $item)
  <tr><td>{{ $item['invoice']->invoice_code }}</td><td>{{ $item['project']->name }}</td><td>{{ $item['invoice']->client?->name ?? $item['project']->client?->name ?? '—' }}</td><td>{{ $item['invoice']->issued_at?->format('d M Y') ?? '—' }}</td><td>{{ $item['invoice']->due_at?->format('d M Y') ?? '—' }}</td><td class="amt">{{ $money::format($item['total']) }}</td><td class="amt">{{ $money::format($item['received']) }}</td><td class="amt">{{ $money::format($item['outstanding']) }}</td><td>@if($item['overdue'])<span class="badge warn">Overdue</span>@else Awaiting payment @endif</td><td><a href="{{ route('director.projects.show', $item['project']) }}">Project analytics</a></td></tr>
@empty<tr><td colspan="10">There are no outstanding issued invoices.</td></tr>@endforelse
</tbody></table></div>
<footer>This page is read-only. ClientInvoice creates a receivable; only ClientPayment records cash received.</footer>
@endsection