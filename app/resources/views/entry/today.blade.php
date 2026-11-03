@extends('layouts.app')
@section('title', 'Entered Today · Buildiwick')
@section('content')
@include('partials.ledger.styles')
<div class="lg-head">
  <div><h2>Entered Today</h2><p>{{ $project->name }} · {{ now()->format('l, d M Y') }} · what you entered today on this project.</p></div>
  <div class="lg-actions">@can('record-expense', $project)<a class="btn btn-primary" href="{{ auth()->user()->role?->name === 'data_entry' ? route('entry.form', $project) : route('ledger.expenses.create', $project) }}">+ Enter more</a>@endcan</div>
</div>
<div class="lg-cards" style="margin-bottom:4px">
  <div class="lg-card"><div class="k">Lines</div><div class="v">{{ $today->count() }}</div></div>
  <div class="lg-card"><div class="k">Total</div><div class="v">TSh {{ \App\Support\Money::format(\App\Services\LedgerAnalyticsService::money((int) $today->where('status', '!=', 'rejected')->sum(fn ($line) => \App\Services\LedgerAnalyticsService::cents($line->total_amount)))) }}</div></div>
</div>
@include('entry._today', ['today' => $today])
@endsection
