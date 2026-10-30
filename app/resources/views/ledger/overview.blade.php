@extends('layouts.app')
@section('title', $project->name.' · Overview')
@section('content')
@include('partials.ledger.styles')
@include('partials.ledger.charts')
@php
  $money = \App\Support\Money::class;
  $t = $report['totals'];
  $periods = ['' => 'All time', 'this_month' => 'This month', 'last_month' => 'Last month', 'this_quarter' => 'This quarter', 'last_quarter' => 'Last quarter', 'this_year' => 'This year'];
  $current = $range['name'] ?? '';
  $chartData = [
      'categories' => collect($report['categories'])->map(fn ($c) => ['label' => $c['label'], 'color' => $c['color']]),
      'totals' => collect($report['categories'])->map(fn ($c) => ['recorded' => $c['recorded'], 'paid' => $c['paid'], 'to_confirm' => $c['to_confirm'], 'to_confirm_count' => $c['to_confirm_count'], 'budget' => $c['budget'], 'count' => $c['count']]),
      'all' => ['recorded' => $t['recorded'], 'paid' => $t['paid'], 'to_confirm' => $t['to_confirm'], 'to_confirm_count' => $t['to_confirm_count'], 'budget' => $t['budget'], 'count' => $t['lines']],
      'monthly' => $report['monthly'],
      'phases' => $report['phases']->where('depth', 1)->map(fn ($p) => ['label' => $p['label'], 'count' => $p['count'], 'recorded' => $p['recorded'], 'budget' => $p['budget'],
          'categories' => $p['categories'], 'href' => route('ledger.expenses', [$project, 'phase' => $p['phase']->id])])->values(),
      'top' => $report['top_items'],
      'topByCategory' => $report['top_by_category'],
  ];
@endphp
<div class="lg-head">
  <div><h2>{{ $project->name }}</h2><p>{{ $project->project_code }}@if($project->location) · {{ $project->location }}@endif @if($project->planned_start_date || $project->expected_completion_date)· {{ $project->planned_start_date?->format('d M Y') ?? '…' }} – {{ $project->expected_completion_date?->format('d M Y') ?? '…' }}@endif · <span class="dot-status {{ $project->status === 'active' ? 'active' : 'zero' }}">{{ ucfirst(str_replace('_', ' ', $project->status)) }}</span></p></div>
  <div class="lg-actions">
    @can('record-expense', $project)<a class="btn btn-primary" href="{{ route('ledger.expenses.create', $project) }}">+ Record Expense</a>@endcan
    <a class="btn btn-ghost" href="{{ route('ledger.expenses', $project) }}">Expenditure</a>
    @can('export-ledger', $project)<a class="btn btn-ghost" href="{{ route('ledger.expenses.export', $project) }}">Excel</a>@endcan
  </div>
</div>

<div class="lg-chips" style="align-items:center">
  @foreach($periods as $code => $label)<a class="lg-chip {{ $current === $code ? 'on' : '' }}" href="{{ route('ledger.overview', $code ? [$project, 'range' => $code] : $project) }}">{{ $label }}</a>@endforeach
  <form method="GET" class="lg-range" id="customRange"><input type="hidden" name="range" value="custom">
    <span class="lg-chip {{ $current === 'custom' ? 'on' : '' }}" style="cursor:default">Custom</span>
    <input type="date" name="from" value="{{ $current === 'custom' ? $range['from']->toDateString() : '' }}" aria-label="From"> <span style="color:var(--ink-dim)">to</span> <input type="date" name="to" value="{{ $current === 'custom' ? $range['to']->toDateString() : '' }}" aria-label="To">
  </form>
  @if($range)<span style="font-size:12px;color:var(--ink-dim)">{{ $range['from']->format('d M Y') }} – {{ $range['to']->format('d M Y') }}</span>@endif
</div>
@error('from')<div class="flash error">{{ $message }}</div>@enderror @error('to')<div class="flash error">{{ $message }}</div>@enderror

<div class="lg-catbar" id="catBar" style="margin-bottom:12px"></div>

<div class="lg-cards">
  <div class="lg-card accent"><div class="k">Recorded spend</div><div class="v" id="cRecorded">TSh {{ $money::format($t['recorded']) }}</div><div class="s"><span id="cLines">{{ number_format($t['lines']) }}</span> lines @if($t['last_entry'])· last {{ \Carbon\Carbon::parse($t['last_entry'])->format('d M Y') }}@endif</div></div>
  <div class="lg-card good"><div class="k">Paid</div><div class="v" id="cPaid">TSh {{ $money::format($t['paid']) }}</div><div class="s">Confirmed by Accounts</div></div>
  <a class="lg-card warn" id="cConfirmLink" href="{{ route('ledger.expenses', [$project, 'status' => 'to_confirm', 'sort' => 'oldest']) }}"><div class="k">To confirm</div><div class="v" id="cConfirm">TSh {{ $money::format($t['to_confirm']) }}</div><div class="s"><span id="cConfirmCount">{{ number_format($t['to_confirm_count']) }}</span> lines waiting</div></a>
  <a class="lg-card" href="{{ route('ledger.structure', $project) }}"><div class="k">Budget used</div><div class="v" id="cUsed">—</div><div class="lg-meter" id="cMeterWrap"><span id="cMeter" style="width:0"></span></div><div class="s" id="cBudget"></div></a>
</div>

<div class="lg-grid">
  <section class="lg-panel span-5">
    <h3>Spend by category</h3><p class="hint">Hover a slice for the amount</p>
    <div class="lg-donut-wrap"><div class="lg-chart" id="donut"></div>
      <div class="lg-legend" style="flex:1;min-width:200px">@foreach($report['categories'] as $code => $c)<div><i style="background:{{ $c['color'] }}"></i><a href="{{ route('ledger.expenses', [$project, 'category' => $code]) }}" style="color:inherit;text-decoration:none">{{ $c['label'] }}</a><b>{{ $money::format($c['share']) }}%</b></div>@endforeach</div>
    </div>
  </section>
  <section class="lg-panel span-7"><h3>Monthly spend</h3><p class="hint">Hover a column for the month's amounts</p><div class="lg-chart" id="monthly"></div></section>
  <section class="lg-panel span-7"><h3>Phases: spend against budget</h3><p class="hint">Each phase includes its subphases. The grey line marks the budget.</p><div class="lg-bars lg-chart" id="phaseBars"></div></section>
  <section class="lg-panel span-5"><h3>Top items</h3><p class="hint">Largest items by recorded amount</p><div class="lg-bars lg-chart" id="topItems"></div></section>

  <section class="lg-panel span-12">
    <h3>Categories against budget</h3><p class="hint">Recorded and paid per main category, with the Director's budget</p>
    <div class="tablewrap" style="border:0"><table class="lg-table lg-cards-sm"><thead><tr><th>Category</th><th class="amt">Lines</th><th class="amt">Recorded</th><th class="amt">Paid</th><th class="amt">To confirm</th><th class="amt">Budget</th><th style="min-width:160px">Used</th></tr></thead><tbody>
      @foreach($report['categories'] as $code => $c)
        <tr><td data-label="Category"><a href="{{ route('ledger.expenses', [$project, 'category' => $code]) }}" class="lg-cat" style="color:inherit;text-decoration:none"><i style="background:{{ $c['color'] }}"></i>{{ $c['label'] }}</a></td><td data-label="Lines" class="amt">{{ number_format($c['count']) }}</td><td data-label="Recorded" class="amt">{{ $money::format($c['recorded']) }}</td><td data-label="Paid" class="amt">{{ $money::format($c['paid']) }}</td><td data-label="To confirm" class="amt">{{ (float) $c['to_confirm'] ? $money::format($c['to_confirm']) : '—' }}</td><td data-label="Budget" class="amt">{{ (float) $c['budget'] > 0 ? $money::format($c['budget']) : '—' }}</td>
          <td data-label="Used">@if($c['used'] !== null)<div class="lg-meter" style="margin-top:3px"><span class="{{ (float) $c['used'] > 100 ? 'over' : ((float) $c['used'] >= 85 ? 'near' : '') }}" style="width:{{ min(100, (float) $c['used']) }}%"></span></div><span class="muted">{{ $money::format($c['used']) }}%</span>@else<span class="muted">No budget</span>@endif</td></tr>
      @endforeach
    </tbody></table></div>
  </section>

  <section class="lg-panel span-12">
    <div style="display:flex;justify-content:space-between;align-items:center"><h3>Latest entries</h3><a class="lg-link" href="{{ route('ledger.expenses', $project) }}">Open Expenditure →</a></div>
    <div class="tablewrap" style="border:0;margin-top:8px"><table class="lg-table lg-cards-sm"><thead><tr><th>ID</th><th>Date</th><th>Item / Description</th><th>Category</th><th>Phase</th><th class="amt">Amount</th><th>Status</th></tr></thead><tbody>
      @forelse($report['recent'] as $line)
        @php($status = $line->ledger_status ?? $line->payment_status)
        <tr><td data-label="ID" class="code">{{ $line->request_code }}</td><td data-label="Date">{{ $line->requested_at?->format('d M Y') }}</td><td data-label="Item">{{ $line->item_description }}</td><td data-label="Category"><span class="lg-cat"><i style="background:{{ \App\Services\LedgerAnalyticsService::CATEGORY_COLORS[$line->main_category] ?? '#999' }}"></i>{{ \App\Services\LedgerAnalyticsService::CATEGORIES[$line->main_category] ?? $line->main_category }}</span></td><td data-label="Phase">{{ $report['tree']->path($line->project_phase_id) }}</td><td data-label="Amount" class="amt">{{ $money::format($line->total_amount) }}</td><td data-label="Status"><span class="lg-pill {{ $status }}">{{ ['paid' => 'Paid', 'part_paid' => 'Part paid', 'unpaid' => 'To confirm'][$status] }}</span></td></tr>
      @empty<tr><td colspan="7" class="lg-empty">Nothing recorded yet. @can('record-expense', $project)<a href="{{ route('ledger.expenses.create', $project) }}">Record the first expense</a>.@endcan</td></tr>@endforelse
    </tbody></table></div>
  </section>
</div>
<script>
(() => {
  const D = @json($chartData), L = window.LedgerCharts, cats = D.categories;
  const confirmBase = {{ Js::from(route('ledger.expenses', [$project, 'status' => 'to_confirm', 'sort' => 'oldest'])) }};
  const render = sel => {
    const totals = sel ? D.totals[sel] : D.all;
    document.getElementById('cRecorded').textContent = 'TSh ' + L.fmt(totals.recorded);
    document.getElementById('cLines').textContent = Number(totals.count).toLocaleString();
    document.getElementById('cPaid').textContent = 'TSh ' + L.fmt(totals.paid);
    document.getElementById('cConfirm').textContent = 'TSh ' + L.fmt(totals.to_confirm);
    document.getElementById('cConfirmCount').textContent = Number(totals.to_confirm_count).toLocaleString();
    document.getElementById('cConfirmLink').href = confirmBase + (sel ? '&category=' + sel : '');
    const budget = Number(totals.budget), used = budget > 0 ? Number(totals.recorded) / budget * 100 : null;
    document.getElementById('cUsed').textContent = used === null ? 'No budget' : used.toFixed(2) + '%';
    document.getElementById('cMeterWrap').hidden = used === null;
    const meter = document.getElementById('cMeter'); meter.style.width = Math.min(100, used || 0) + '%'; meter.className = used > 100 ? 'over' : (used >= 85 ? 'near' : '');
    document.getElementById('cBudget').textContent = used === null ? 'The Director sets it under Phases & Budget' : 'of TSh ' + L.fmt(budget);
    L.donut(document.getElementById('donut'), cats, Object.fromEntries(Object.entries(D.totals).map(([c, v]) => [c, v.recorded])), sel);
    L.monthly(document.getElementById('monthly'), D.monthly, cats, sel);
    L.bars(document.getElementById('phaseBars'), D.phases.map(p => ({label: p.label, href: p.href + (sel ? '&category=' + sel : ''),
      value: sel ? p.categories[sel].recorded : p.recorded, budget: sel ? p.categories[sel].budget : p.budget, sub: sel ? '' : p.count + ' lines'})), sel ? cats[sel].color : 'var(--primary)', 'No phases with spend.');
    L.bars(document.getElementById('topItems'), (sel ? D.topByCategory[sel] : D.top).map(i => ({label: i.name.length > 34 ? i.name.slice(0, 33) + '…' : i.name, value: i.total, sub: i.count + ' lines'})), '#8E6CEF', 'Nothing recorded.');
  };
  L.categoryBar(document.getElementById('catBar'), cats, render);
  render(null);
  const custom = document.getElementById('customRange');
  custom.querySelectorAll('input[type=date]').forEach(i => i.addEventListener('change', () => { if (custom.from.value && custom.to.value) custom.submit(); }));
})();
</script>
@endsection
