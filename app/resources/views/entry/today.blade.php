@extends('layouts.app')
@section('title', 'Entered Today · Buildiwick')
@section('content')
@include('partials.ledger.styles')
<div class="lg-head">
  <div><h2>Entered Today</h2><p>{{ now()->format('l, d M Y') }} · everything you entered today, on every project.</p></div>
  <div class="lg-actions">@if(auth()->user()->role?->name === 'data_entry')<a class="btn btn-primary" href="{{ route('entry.index') }}">+ New Entry</a>@endif</div>
</div>
<div class="lg-cards" style="margin-bottom:4px">
  <div class="lg-card"><div class="k">Lines</div><div class="v">{{ $today->count() }}</div></div>
  <div class="lg-card"><div class="k">Total</div><div class="v">TSh {{ \App\Support\Money::format(\App\Services\LedgerAnalyticsService::money((int) $today->where('status', '!=', 'rejected')->sum(fn ($line) => \App\Services\LedgerAnalyticsService::cents($line->total_amount)))) }}</div></div>
</div>
@include('entry._today', ['today' => $today, 'showProject' => true])
@endsection
