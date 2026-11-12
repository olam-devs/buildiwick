@extends('layouts.app')
@section('title', 'My Entries · Buildiwick')
@section('content')
@include('partials.ledger.styles')
<div class="lg-head">
  <div><h2>My Entries</h2><p>{{ $project->name }} · what you entered on this project.</p></div>
  <div class="lg-actions">@can('record-expense', $project)<a class="btn btn-primary" href="{{ auth()->user()->role?->name === 'data_entry' ? route('entry.form', $project) : route('ledger.expenses.create', $project) }}">+ Enter more</a>@endcan</div>
</div>
@include('entry._period', ['route' => fn (array $q) => route('entry.today.project', [$project] + $q)])
{{-- Data Entry does not need the line count and total (no analytics for this role). --}}
@unless(auth()->user()->role?->name === 'data_entry')
<div class="lg-cards" style="margin-bottom:4px">
  <div class="lg-card"><div class="k">Lines</div><div class="v">{{ $today->count() }}</div></div>
  <div class="lg-card"><div class="k">Total</div><div class="v">TSh {{ \App\Support\Money::format(\App\Services\LedgerAnalyticsService::money((int) $today->where('status', '!=', 'rejected')->sum(fn ($line) => \App\Services\LedgerAnalyticsService::cents($line->total_amount)))) }}</div></div>
</div>
@endunless
@include('entry._today', ['today' => $today, 'title' => $period['key'] === 'today' && $period['by'] === 'entered' && $period['q'] === '' ? 'Entered today' : ($period['q'] !== '' ? 'Found' : 'Entered'), 'showDate' => ! $period['from']->isToday(), 'dateBy' => $period['by'], 'searching' => $period['q'] !== ''])
@endsection
