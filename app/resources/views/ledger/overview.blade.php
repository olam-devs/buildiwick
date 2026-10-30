@extends('layouts.app')
@section('title', $project->name.' · Overview')
@section('content')
@include('partials.ledger.styles')
@php
  $money = \App\Support\Money::class;
  $t = $report['totals'];
  $periods = ['' => 'All time', 'this_month' => 'This month', 'last_month' => 'Last month', 'this_quarter' => 'This quarter', 'last_quarter' => 'Last quarter', 'this_year' => 'This year'];
  $current = $range['name'] ?? '';
  $topPhases = $report['phases']->where('depth', 1);
  $maxPhase = max(1, $topPhases->max(fn ($p) => max((float) $p['recorded'], (float) $p['budget'])));
@endphp
<div class="lg-head">
  <div><h2>{{ $project->name }}</h2><p>{{ $project->project_code }}@if($project->location) · {{ $project->location }}@endif · <span class="dot-status {{ $project->status === 'active' ? 'active' : 'zero' }}">{{ ucfirst(str_replace('_', ' ', $project->status)) }}</span></p></div>
  <div class="lg-actions">
    @can('record-expense', $project)<a class="btn btn-primary" href="{{ route('ledger.expenses.create', $project) }}">+ Record Expense</a>@endcan
    <a class="btn btn-ghost" href="{{ route('ledger.expenses', $project) }}">Expenditure</a>
    <a class="btn btn-ghost" href="{{ route('ledger.expenses.export', $project) }}">Excel</a>
  </div>
</div>

<div class="lg-chips">@foreach($periods as $code => $label)<a class="lg-chip {{ $current === $code ? 'on' : '' }}" href="{{ route('ledger.overview', $code ? [$project, 'range' => $code] : $project) }}">{{ $label }}</a>@endforeach
  @if($range)<span class="lg-chip" style="border:0;background:none">{{ $range['from']->format('d M Y') }} – {{ $range['to']->format('d M Y') }}</span>@endif</div>

<div class="lg-cards">
  <div class="lg-card accent"><div class="k">Recorded spend</div><div class="v">TSh {{ $money::format($t['recorded']) }}</div><div class="s">{{ number_format($t['lines']) }} lines @if($t['last_entry'])· last {{ \Carbon\Carbon::parse($t['last_entry'])->format('d M Y') }}@endif</div></div>
  <div class="lg-card good"><div class="k">Paid</div><div class="v">TSh {{ $money::format($t['paid']) }}</div><div class="s">Confirmed by Accounts</div></div>
  <a class="lg-card warn" href="{{ route('ledger.expenses', [$project, 'status' => 'to_confirm']) }}"><div class="k">To confirm</div><div class="v">TSh {{ $money::format($t['to_confirm']) }}</div><div class="s">{{ number_format($t['to_confirm_count']) }} lines waiting</div></a>
  <a class="lg-card {{ $t['used'] !== null && (float) $t['used'] > 100 ? 'danger' : '' }}" href="{{ route('ledger.structure', $project) }}"><div class="k">Budget used</div>
    @if($t['used'] !== null)<div class="v">{{ $money::format($t['used']) }}%</div><div class="lg-meter"><span class="{{ (float) $t['used'] > 100 ? 'over' : ((float) $t['used'] >= 85 ? 'near' : '') }}" style="width:{{ min(100, (float) $t['used']) }}%"></span></div><div class="s">of TSh {{ $money::format($t['budget']) }}</div>
    @else<div class="v" style="font-size:16px;color:var(--ink-dim)">No budget</div><div class="s">The Director sets it under Phases &amp; Budget</div>@endif</a>
</div>

<div class="lg-grid">
  <section class="lg-panel span-5">
    <h3>Spend by category</h3><p class="hint">Share of recorded spend</p>
    <div class="lg-donut-wrap">
      @include('partials.ledger.donut', ['segments' => collect($report['categories'])->map(fn ($c) => ['label' => $c['label'], 'value' => $c['recorded'], 'color' => $c['color']])->values()->all(), 'center' => $t['lines'] ? number_format($t['lines']) : '0', 'centerSub' => 'lines'])
      <div class="lg-legend" style="flex:1;min-width:200px">@foreach($report['categories'] as $code => $c)<div><i style="background:{{ $c['color'] }}"></i><a href="{{ route('ledger.expenses', [$project, 'category' => $code]) }}" style="color:inherit;text-decoration:none">{{ $c['label'] }}</a><b>{{ $money::format($c['share']) }}%</b></div>@endforeach</div>
    </div>
  </section>
  <section class="lg-panel span-7">
    <h3>Monthly spend</h3><p class="hint">Recorded spend per month, by category</p>
    @include('partials.ledger.monthly', ['months' => $report['monthly']])
  </section>

  <section class="lg-panel span-7">
    <h3>Phases: spend against budget</h3><p class="hint">Each phase includes its subphases. The grey line marks the budget.</p>
    <div class="lg-bars">
      @forelse($topPhases as $phase)
        <div class="lg-bar">
          <a href="{{ route('ledger.expenses', [$project, 'phase' => $phase['phase']->id]) }}" style="color:inherit;text-decoration:none">{{ $phase['label'] }}<small>{{ $phase['count'] }} lines @if($phase['used'] !== null)· {{ $money::format($phase['used']) }}% of budget @endif</small></a>
          <div class="track"><span class="fill" style="width:{{ (float) $phase['recorded'] / $maxPhase * 100 }}%;background:{{ $phase['used'] !== null && (float) $phase['used'] > 100 ? 'var(--danger)' : 'var(--primary)' }}"></span>@if((float) $phase['budget'] > 0)<span style="position:absolute;top:-2px;bottom:-2px;width:2px;background:#5D6A73;left:{{ min(99.5, (float) $phase['budget'] / $maxPhase * 100) }}%"></span>@endif</div>
          <span class="amt">TSh {{ $money::format($phase['recorded']) }}@if((float) $phase['budget'] > 0)<small>of {{ $money::format($phase['budget']) }}</small>@endif</span>
        </div>
      @empty<p class="hint">No phases yet.</p>@endforelse
    </div>
  </section>
  <section class="lg-panel span-5">
    <h3>Top items</h3><p class="hint">Largest items by recorded amount</p>
    <div class="lg-bars">
      @php($maxItem = max(1, $report['top_items']->max(fn ($i) => (float) $i['total'])))
      @forelse($report['top_items'] as $item)
        <div class="lg-bar" style="grid-template-columns:minmax(110px,1fr) 1.1fr auto"><span>{{ \Illuminate\Support\Str::limit($item['name'], 32) }}<small>{{ $item['count'] }} lines</small></span><div class="track"><span class="fill" style="width:{{ (float) $item['total'] / $maxItem * 100 }}%;background:#8E6CEF"></span></div><span class="amt">{{ $money::format($item['total']) }}</span></div>
      @empty<p class="hint">Nothing recorded yet.</p>@endforelse
    </div>
  </section>

  <section class="lg-panel span-12">
    <h3>Categories against budget</h3><p class="hint">Recorded and paid per main category, with the Director's budget</p>
    <div class="tablewrap" style="border:0"><table class="lg-table"><thead><tr><th>Category</th><th class="amt">Lines</th><th class="amt">Recorded</th><th class="amt">Paid</th><th class="amt">Budget</th><th style="min-width:160px">Used</th></tr></thead><tbody>
      @foreach($report['categories'] as $code => $c)
        <tr><td><span class="lg-cat"><i style="background:{{ $c['color'] }}"></i>{{ $c['label'] }}</span></td><td class="amt">{{ number_format($c['count']) }}</td><td class="amt">{{ $money::format($c['recorded']) }}</td><td class="amt">{{ $money::format($c['paid']) }}</td><td class="amt">{{ (float) $c['budget'] > 0 ? $money::format($c['budget']) : '—' }}</td>
          <td>@if($c['used'] !== null)<div class="lg-meter" style="margin-top:3px"><span class="{{ (float) $c['used'] > 100 ? 'over' : ((float) $c['used'] >= 85 ? 'near' : '') }}" style="width:{{ min(100, (float) $c['used']) }}%"></span></div><span class="muted">{{ $money::format($c['used']) }}%</span>@else<span class="muted">No budget</span>@endif</td></tr>
      @endforeach
    </tbody></table></div>
  </section>

  <section class="lg-panel span-12">
    <div style="display:flex;justify-content:space-between;align-items:center"><h3>Latest entries</h3><a class="lg-link" href="{{ route('ledger.expenses', $project) }}">Open Expenditure →</a></div>
    <div class="tablewrap" style="border:0;margin-top:8px"><table class="lg-table"><thead><tr><th>ID</th><th>Date</th><th>Item / Description</th><th>Category</th><th>Phase</th><th class="amt">Amount</th><th>Status</th></tr></thead><tbody>
      @forelse($report['recent'] as $line)
        <tr><td class="code">{{ $line->request_code }}</td><td>{{ $line->requested_at?->format('d M Y') }}</td><td>{{ $line->item_description }}</td><td><span class="lg-cat"><i style="background:{{ \App\Services\LedgerAnalyticsService::CATEGORY_COLORS[$line->main_category] ?? '#999' }}"></i>{{ \App\Services\LedgerAnalyticsService::CATEGORIES[$line->main_category] ?? $line->main_category }}</span></td><td>{{ $report['tree']->path($line->project_phase_id) }}</td><td class="amt">{{ $money::format($line->total_amount) }}</td><td><span class="lg-pill {{ ($line->ledger_status ?? $line->payment_status) }}">{{ ['paid' => 'Paid', 'part_paid' => 'Part paid', 'unpaid' => 'To confirm'][($line->ledger_status ?? $line->payment_status)] }}</span></td></tr>
      @empty<tr><td colspan="7" class="lg-empty">Nothing recorded yet. @can('record-expense', $project)<a href="{{ route('ledger.expenses.create', $project) }}">Record the first expense</a>.@endcan</td></tr>@endforelse
    </tbody></table></div>
  </section>
</div>
@endsection
