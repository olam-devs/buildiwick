@extends('layouts.app')
@section('title', 'To Confirm · Buildiwick')
@section('content')
@include('partials.ledger.styles')
@php
  $money = \App\Support\Money::class;
  $categories = \App\Services\LedgerAnalyticsService::CATEGORIES;
  $colors = \App\Services\LedgerAnalyticsService::CATEGORY_COLORS;
  $byProject = $lines->groupBy('project_id');
@endphp
<div class="lg-head">
  <div><h2>To Confirm</h2><p>Lines recorded on site that still need the amount paid confirmed. Oldest first.</p></div>
</div>
@if ($errors->any())<div class="flash error"><ul style="margin:0;padding-left:20px;">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<div class="lg-cards">
  <div class="lg-card warn"><div class="k">Waiting</div><div class="v">{{ number_format($lines->count()) }} lines</div><div class="s">across {{ $byProject->count() }} projects</div></div>
  <div class="lg-card"><div class="k">Amount to confirm</div><div class="v">TSh {{ $money::format(\App\Services\LedgerAnalyticsService::money((int) $lines->sum('ledger_open_c'))) }}</div></div>
  <div class="lg-card"><div class="k">Oldest</div><div class="v" style="font-size:18px">{{ $lines->first()?->requested_at?->format('d M Y') ?? '—' }}</div></div>
</div>

@forelse($byProject as $projectLines)
  @php($project = $projectLines->first()->project)
  <section class="lg-panel" style="margin-bottom:12px">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap"><h3>{{ $project->name }} <span class="muted" style="display:inline;font-weight:400;font-size:12px;color:var(--ink-dim)">{{ $projectLines->count() }} lines · TSh {{ $money::format(\App\Services\LedgerAnalyticsService::money((int) $projectLines->sum('ledger_open_c'))) }}</span></h3><a class="lg-link" href="{{ route('ledger.expenses', [$project, 'status' => 'to_confirm', 'sort' => 'oldest']) }}">Open in Expenditure →</a></div>
    <div class="tablewrap" style="border:0;margin-top:8px"><table class="lg-table"><thead><tr><th>ID</th><th>Date</th><th>Item / Description</th><th>Category</th><th>Phase</th><th class="amt">Qty</th><th class="amt">Total</th><th class="amt">Still to confirm</th><th>Recorded by</th><th></th></tr></thead><tbody>
      @foreach($projectLines as $line)
        <tr><td class="code">{{ $line->request_code }}</td><td style="white-space:nowrap">{{ $line->requested_at?->format('d M Y') }}</td><td>{{ $line->item_description }}</td>
          <td><span class="lg-cat"><i style="background:{{ $colors[$line->main_category] ?? '#999' }}"></i>{{ $categories[$line->main_category] ?? $line->main_category }}</span></td>
          <td>{{ $line->phase?->stage_label }}</td><td class="amt">{{ $line->quantity !== null ? rtrim(rtrim($line->quantity, '0'), '.') : '—' }} {{ $line->unit }}</td>
          <td class="amt">{{ $money::format($line->total_amount) }}</td><td class="amt"><b>{{ $money::format($line->approved_outstanding_amount) }}</b></td><td>{{ $line->requestedBy?->name ?? '—' }}</td>
          <td><button class="btn btn-primary" style="padding:5px 10px" type="button" data-confirm-url="{{ route('ledger.expenses.confirm', [$project, $line]) }}" data-code="{{ $line->request_code }}" data-item="{{ $line->item_description }}" data-total="{{ $line->total_amount }}" data-outstanding="{{ $line->approved_outstanding_amount }}" data-paid="{{ $line->paid_to_date }}">Confirm</button></td></tr>
      @endforeach
    </tbody></table></div>
  </section>
@empty
  <section class="lg-panel lg-empty">Nothing is waiting. Every recorded line has been confirmed.</section>
@endforelse
@include('ledger.partials.confirm-dialog')
@endsection
