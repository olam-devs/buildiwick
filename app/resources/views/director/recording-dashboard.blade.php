@extends('layouts.app')
@section('title', 'Dashboard · Buildiwick')
@section('content')
@include('partials.ledger.styles')
@include('director.partials.kpi-styles')
@include('partials.ledger.charts')
@php
  $money = \App\Support\Money::class;
  $t = $portfolio['totals'];
  $presets = ['this_month' => 'This month', 'last_month' => 'Last month', 'this_quarter' => 'This quarter', 'last_quarter' => 'Last quarter', 'this_year' => 'This year', 'last_year' => 'Last year'];
  $change = function ($now, $before) use ($money) {
      $diff = $money::difference($now, $before);
      if ($money::compare($before, '0') === 0) { return $money::compare($now, '0') === 0 ? 'No change' : 'New this period'; }
      $pct = $money::percentage(ltrim($diff, '-'), $before);
      return ($money::compare($diff, '0') >= 0 ? '▲ ' : '▼ ').$money::format($pct).'% vs previous';
  };
  $rows = $portfolio['rows']->sortByDesc(fn ($r) => (float) $r['recorded'])->values();
  $chartData = [
      'categories' => collect($portfolio['categories'])->map(fn ($c) => ['label' => $c['label'], 'color' => $c['color']]),
      'totals' => collect($portfolio['categories'])->map(fn ($c) => $c['recorded']),
      'recorded' => $t['recorded'], 'lines' => $t['lines'],
      'monthly' => $portfolio['monthly'],
      'projects' => $rows->map(fn ($r) => ['label' => \Illuminate\Support\Str::limit($r['project']->name, 34), 'href' => route('ledger.overview', $r['project']),
          'recorded' => $r['recorded'], 'lines' => $r['lines'], 'by_category' => $r['by_category']])->values(),
  ];
@endphp
<div class="lg-head">
  <div><h2>Company Dashboard</h2><p>{{ $range['label'] }} · compared with {{ $previousRange['label'] }}</p></div>
  <div class="lg-actions"><a class="btn btn-ghost" href="{{ route('ledger.export', array_filter(['range' => $range['name'], 'from' => $range['name'] === 'custom' ? $range['from']->toDateString() : null, 'to' => $range['name'] === 'custom' ? $range['to']->toDateString() : null])) }}" title="Every project: summary, categories, months and all lines for this period">Download Excel</a></div>
</div>
<div class="lg-chips" style="align-items:center">@foreach($presets as $code => $label)<a class="lg-chip {{ $range['name'] === $code ? 'on' : '' }}" href="{{ route('director.index', ['range' => $code]) }}">{{ $label }}</a>@endforeach
  <form method="GET" class="lg-range" id="customRange"><input type="hidden" name="range" value="custom">
    <span class="lg-chip {{ $range['name'] === 'custom' ? 'on' : '' }}" style="cursor:default">Custom</span>
    <input type="date" name="from" value="{{ $range['name'] === 'custom' ? $range['from']->toDateString() : '' }}" aria-label="From"> <span style="color:var(--ink-dim)">to</span> <input type="date" name="to" value="{{ $range['name'] === 'custom' ? $range['to']->toDateString() : '' }}" aria-label="To">
  </form>
</div>
@error('from')<div class="flash error">{{ $message }}</div>@enderror @error('to')<div class="flash error">{{ $message }}</div>@enderror
<div class="lg-catbar" id="catBar" style="margin-bottom:12px"></div>

<div class="lg-cards">
  <div class="lg-card accent"><div class="k">Recorded spend</div><div class="v" id="cRecorded">TSh {{ $money::format($t['recorded']) }}</div><div class="s" id="cRecordedSub">{{ $change($t['recorded'], $previous['recorded']) }}</div></div>
  <div class="lg-card good"><div class="k">Paid</div><div class="v">TSh {{ $money::format($t['paid']) }}</div><div class="s">{{ $change($t['paid'], $previous['paid']) }}</div></div>
  <a class="lg-card warn" href="{{ route('ledger.to-confirm') }}"><div class="k">To confirm</div><div class="v">TSh {{ $money::format($t['to_confirm']) }}</div><div class="s">{{ number_format($t['to_confirm_count']) }} lines in this period</div></a>
  <a class="lg-card {{ $attention->isNotEmpty() ? 'danger' : '' }}" href="#attention"><div class="k">Projects needing attention</div><div class="v">{{ $attention->count() }}</div><div class="s">of {{ $t['active_projects'] }} active projects</div></a>
</div>

<div class="lg-grid">
  <section class="lg-panel span-8"><h3>Monthly spend</h3><p class="hint">All projects, by main category. Hover a column for the amounts.</p><div class="lg-chart" id="monthly"></div></section>
  <section class="lg-panel span-4"><h3>Spend by category</h3><p class="hint">This period. Hover a slice for the amount.</p>
    <div style="display:flex;justify-content:center" class="lg-chart" id="donut"></div>
    <div class="lg-legend" style="margin-top:10px">@foreach($portfolio['categories'] as $c)<div><i style="background:{{ $c['color'] }}"></i>{{ $c['label'] }}<b>{{ $money::format($c['share']) }}%</b></div>@endforeach</div>
  </section>

  <section class="lg-panel span-6"><h3>Top projects by spend</h3><p class="hint" id="topHint">This period</p>
    <div class="lg-bars lg-chart" id="topProjects"></div>
  </section>
  <section class="lg-panel span-6" id="attention"><h3>Needs attention</h3><p class="hint">From your warning rules (Default Warning Rules, or a project's own limits)</p>
    @forelse($attention->take(8) as $row)
      <div style="padding:9px 0;border-top:1px solid var(--line)"><a href="{{ route('director.projects.warnings', $row['project']) }}" style="font-weight:600;color:var(--ink);text-decoration:none">{{ $row['project']->name }}</a> <span class="badge warn">{{ count($row['warnings']) }}</span>
        <ul style="margin:4px 0 0;padding-left:18px;color:var(--ink-soft);font-size:12px">@foreach(array_slice($row['warnings'], 0, 3) as $warning)<li>{{ $warning['message'] }}</li>@endforeach</ul></div>
    @empty<p class="hint">No warnings. Every project is within your limits.</p>@endforelse
    @if($attention->count() > 8)<p class="hint">{{ $attention->count() - 8 }} more projects have warnings.</p>@endif
  </section>

  <section class="lg-panel span-12"><div style="display:flex;justify-content:space-between;align-items:center"><h3>Company KPI targets</h3><a class="lg-link" href="{{ route('director.kpis.index') }}">Manage targets →</a></div>
    @if($companyKpis->isEmpty())<p class="hint" style="margin-top:6px">No company targets cover today. Set them under Company KPI Targets.</p>
    @else<div class="tablewrap" style="border:0;margin-top:8px"><table class="lg-table"><thead><tr><th>Measure</th><th>Period</th><th class="amt">Target</th><th class="amt">Actual</th><th>Progress</th><th>Status</th></tr></thead><tbody>@foreach($companyKpis as $kpi)@include('director.partials.kpi-row', ['row' => $kpi])@endforeach</tbody></table></div>@endif
  </section>

  <section class="lg-panel span-12"><div style="display:flex;justify-content:space-between;align-items:center"><h3>All projects</h3><a class="lg-link" href="{{ route('ledger.index') }}">Open projects list →</a></div>
    <div class="tablewrap" style="border:0;margin-top:8px"><table class="lg-table"><thead><tr><th>Project</th><th class="amt">Spend this period</th><th class="amt">To confirm</th><th style="min-width:150px">Budget used (to date)</th><th>Last entry</th></tr></thead><tbody>
      @foreach($rows as $row)<tr><td><a href="{{ route('ledger.overview', $row['project']) }}" style="font-weight:600;color:var(--ink);text-decoration:none">{{ $row['project']->name }}</a><span class="muted">{{ $row['project']->project_code }}</span></td><td class="amt">{{ $money::format($row['recorded']) }}</td><td class="amt">{{ $row['to_confirm_count'] ? $money::format($row['to_confirm']) : '—' }}</td>
        <td>@if($row['used'] !== null)<div class="lg-meter" style="margin-top:3px"><span class="{{ (float) $row['used'] > 100 ? 'over' : ((float) $row['used'] >= 85 ? 'near' : '') }}" style="width:{{ min(100, (float) $row['used']) }}%"></span></div><span class="muted">{{ $money::format($row['used']) }}%</span>@else<span class="muted">No budget</span>@endif</td>
        <td>{{ $row['last_entry'] ? \Carbon\Carbon::parse($row['last_entry'])->format('d M Y') : '—' }}</td></tr>@endforeach
    </tbody></table></div>
  </section>
</div>
<script>
(() => {
  const D = @json($chartData), L = window.LedgerCharts, cats = D.categories, sub = document.getElementById('cRecordedSub').textContent;
  const render = sel => {
    document.getElementById('cRecorded').textContent = 'TSh ' + L.fmt(sel ? D.totals[sel] : D.recorded);
    document.getElementById('cRecordedSub').textContent = sel ? cats[sel].label + ' only' : sub;
    document.getElementById('topHint').textContent = sel ? 'This period · ' + cats[sel].label : 'This period';
    L.monthly(document.getElementById('monthly'), D.monthly, cats, sel);
    L.donut(document.getElementById('donut'), cats, D.totals, sel, 'TSh total');
    L.bars(document.getElementById('topProjects'), D.projects.map(p => ({label: p.label, href: p.href, value: sel ? p.by_category[sel] : p.recorded, sub: sel ? '' : p.lines + ' lines'}))
      .sort((a, b) => b.value - a.value).slice(0, 8), sel ? cats[sel].color : 'var(--primary)', 'Nothing recorded in this period.');
  };
  L.categoryBar(document.getElementById('catBar'), cats, render);
  render(null);
  const custom = document.getElementById('customRange');
  custom.querySelectorAll('input[type=date]').forEach(i => i.addEventListener('change', () => { if (custom.from.value && custom.to.value) custom.submit(); }));
})();
</script>
@endsection
