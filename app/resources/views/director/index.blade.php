@extends('layouts.app')
@section('title', 'Director Analytics · Buildiwick')
@section('content')
@php($money = '\App\Support\Money')
@php($maxProjectAmount = max(1, (float) collect($report['project_chart'])->max(fn ($item) => max((float) $item['budget'], (float) $item['approved'], (float) $item['actual']))))
@php($maxCashAmount = max(1, (float) $report['trend']->max(fn ($item) => max((float) $item['in'], (float) $item['out']))))
<div class="topline">
  <div><h2>Director Dashboard</h2><p>Organization-wide executive overview · Current position is separate from activity during the selected period.</p></div>
  <div class="director-actions"><a class="btn btn-ghost" href="{{ route('director.portfolio.export', request()->query()) }}">Project Portfolio CSV</a><a class="btn btn-ghost" href="{{ route('director.summary.export', request()->query()) }}">Financial Summary CSV</a></div>
</div>

<form method="GET" class="panel director-filter form-section">
  <div class="row3">
    <div class="field"><label for="range">Activity date range</label><select id="range" name="range"><option value="this_month" @selected($range['name'] === 'this_month')>This Month</option><option value="last_3_months" @selected($range['name'] === 'last_3_months')>Last 3 Months</option><option value="this_year" @selected($range['name'] === 'this_year')>This Year</option><option value="custom" @selected($range['name'] === 'custom')>Custom</option></select></div>
    <div class="field"><label for="from">Custom From</label><input id="from" name="from" type="date" value="{{ $range['name'] === 'custom' ? $range['from']->toDateString() : '' }}"></div><div class="field"><label for="to">Custom To</label><input id="to" name="to" type="date" value="{{ $range['name'] === 'custom' ? $range['to']->toDateString() : '' }}"></div>
  </div>
  <button class="btn btn-ghost" type="submit">Apply Date Range</button>
  <p class="form-context">Selected activity: {{ $range['from']->format('d M Y') }} – {{ $range['to']->format('d M Y') }}. Cash, receivables, payables, estimates and budgets below are current position unless marked “during period”.</p>
</form>

<section class="tiles director-tiles">
  <div class="tile"><div class="label">Active Projects</div><div class="value">{{ $report['summary']['active_projects'] }}</div><div class="sub">Existing project status = active</div></div>
  <div class="tile"><div class="label">Estimated Client Value</div><div class="value">TSh {{ $money::format($report['summary']['estimated_client_value']) }}</div><div class="sub">Latest issued estimate per project</div></div>
  <div class="tile"><div class="label">Total Invoiced</div><div class="value">TSh {{ $money::format($report['summary']['invoiced_current']) }}</div><div class="sub">Current issued invoices</div></div>
  <div class="tile"><div class="label">Client Payments Received</div><div class="value">TSh {{ $money::format($report['summary']['received_current']) }}</div><div class="sub">All actual ClientPayment events</div></div>
  <div class="tile"><div class="label">Actual Expenses Paid</div><div class="value">TSh {{ $money::format($report['summary']['expenses_current']) }}</div><div class="sub">All actual ExpensePayment events</div></div>
  <div class="tile"><div class="label">Recorded Cash Balance</div><div class="value">TSh {{ $money::format($report['summary']['cash_balance']) }}</div><div class="sub">Current cash position · not profit</div></div>
  <div class="tile"><div class="label">Outstanding Receivables</div><div class="value">TSh {{ $money::format($report['summary']['receivables']) }}</div><div class="sub"><a href="{{ route('director.receivables.index') }}">Read-only receivables</a></div></div>
  <div class="tile"><div class="label">Outstanding Approved Payables</div><div class="value">TSh {{ $money::format($report['summary']['payables']) }}</div><div class="sub"><a href="{{ route('director.payables.index') }}">Read-only approved payables</a></div></div>
</section>

<section class="panelrow director-charts">
  <div class="panel"><h3>Budget vs Approved vs Actual by Project</h3><p class="form-context">Current internal budget, approved commitments, and actual payments. Bars share a common scale.</p>
    @forelse($report['project_chart'] as $item)
      <div class="chart-project"><div class="chart-project-name">{{ $item['name'] }}</div>
        @foreach([['Budget','budget','#2490EF'],['Approved','approved','#F5A623'],['Actual','actual','#2FA36E']] as [$label,$key,$color])
          <div class="chart-line"><span>{{ $label }}</span><div class="chart-track"><i style="width:{{ min(100, (float) $item[$key] / $maxProjectAmount * 100) }}%;background:{{ $color }}"></i></div><b>TSh {{ $money::format($item[$key], 0) }}</b></div>
        @endforeach
      </div>
    @empty<div class="phase-empty">No projects to compare.</div>@endforelse
    <h3 class="chart-subheading">Invoiced vs Received by Project · Current</h3>
    @foreach($report['project_chart'] as $item)
      @php($invoiceScale = max(1, (float) $item['invoiced'], (float) $item['received']))
      <div class="chart-project"><div class="chart-project-name">{{ $item['name'] }}</div>
        @foreach([['Invoiced','invoiced','#2490EF'],['Received','received','#2FA36E']] as [$label,$key,$color])
          <div class="chart-line"><span>{{ $label }}</span><div class="chart-track"><i style="width:{{ min(100, (float) $item[$key] / $invoiceScale * 100) }}%;background:{{ $color }}"></i></div><b>TSh {{ $money::format($item[$key], 0) }}</b></div>
        @endforeach
      </div>
    @endforeach
  </div>
  <div class="panel"><h3>Actual Expense Payments by Category</h3><p class="form-context">All recorded ExpensePayment events, grouped using the established expense categories.</p>
    @foreach($report['categories'] as $item)
      <div class="chart-line chart-single"><span>{{ $item['label'] }}</span><div class="chart-track"><i style="width:{{ min(100, (float) $item['actual'] / max(1, (float) $report['summary']['expenses_current']) * 100) }}%"></i></div><b>TSh {{ $money::format($item['actual'], 0) }}</b></div>
    @endforeach
    <h3 class="chart-subheading">Actual Expense Payments by Phase</h3>
    @foreach($report['phases'] as $item)
      <div class="chart-line chart-single"><span>{{ $item['label'] }}</span><div class="chart-track"><i style="width:{{ min(100, (float) $item['actual'] / max(1, (float) $report['summary']['expenses_current']) * 100) }}%;background:#2FA36E"></i></div><b>TSh {{ $money::format($item['actual'], 0) }}</b></div>
    @endforeach
  </div>
</section>

<section class="panel form-section"><h3>Period Activity · {{ $range['from']->format('d M Y') }} – {{ $range['to']->format('d M Y') }}</h3><div class="tiles period-tiles"><div class="tile"><div class="label">Invoices Issued During Period</div><div class="value">TSh {{ $money::format($report['summary']['invoiced_period']) }}</div></div><div class="tile"><div class="label">Client Payments During Period</div><div class="value">TSh {{ $money::format($report['summary']['received_period']) }}</div></div><div class="tile"><div class="label">Expense Payments During Period</div><div class="value">TSh {{ $money::format($report['summary']['expenses_period']) }}</div></div></div><h3>Cash Movement by Month</h3><p class="form-context">Money In is ClientPayment; Money Out is ExpensePayment. Net movement is cash movement, not profit.</p>
  <div class="cash-chart">
    @forelse($report['trend'] as $month)
      <div class="cash-month"><strong>{{ $month['month'] }}</strong><div><small>In</small><div class="chart-track"><i style="width:{{ min(100, (float) $month['in'] / $maxCashAmount * 100) }}%;background:#2FA36E"></i></div><b>{{ $money::format($month['in'], 0) }}</b></div><div><small>Out</small><div class="chart-track"><i style="width:{{ min(100, (float) $month['out'] / $maxCashAmount * 100) }}%;background:#F5A623"></i></div><b>{{ $money::format($month['out'], 0) }}</b></div><small>Net movement: TSh {{ $money::format($month['net']) }}</small></div>
    @empty<div class="phase-empty">No monthly movement in this period.</div>@endforelse
  </div>
</section>

<section class="panel form-section"><div class="section-heading"><div><h3>Project Portfolio</h3><p class="form-context">Current cumulative financial position. Select a project for phase, category, and budget utilization detail.</p></div><a href="{{ route('director.transactions.index', ['range' => $range['name'], 'from' => $range['from']->toDateString(), 'to' => $range['to']->toDateString()]) }}">Transaction Register</a></div>
  <div class="tablewrap"><table class="director-portfolio"><thead><tr><th>Project</th><th>Client</th><th>Current Phase</th><th>Status</th><th>Est. Value</th><th>Budget</th><th>Approved</th><th>Actual Paid</th><th>Receivables</th><th>Payables</th><th>Cash Balance</th><th>Action</th></tr></thead><tbody>
  @forelse($report['projects'] as $row)
    <tr><td class="name">{{ $row['project']->name }}<small>{{ $row['project']->project_code }}</small></td><td>{{ $row['project']->client?->name ?? '—' }}</td><td>{{ $row['current_phase'] ?? '—' }}</td><td><span class="dot-status {{ $row['project']->status === 'active' ? 'active' : 'zero' }}">{{ ucfirst(str_replace('_',' ',$row['project']->status)) }}</span>@if($row['over_budget'])<span class="badge warn">Over budget</span>@elseif($row['no_budget'])<span class="badge warn">No budget set</span>@endif</td>
    <td class="amt">{{ $money::format($row['estimated_client_value'], 0) }}</td><td class="amt">{{ $row['no_budget'] ? 'No budget set' : $money::format($row['budget'], 0) }}</td><td class="amt">{{ $money::format($row['approved_commitments'], 0) }}</td><td class="amt">{{ $money::format($row['actual_expenses'], 0) }}</td><td class="amt"><a href="{{ route('director.receivables.index', ['project_id' => $row['project']->id]) }}">{{ $money::format($row['receivables_current'], 0) }}</a></td><td class="amt"><a href="{{ route('director.payables.index', ['project_id' => $row['project']->id]) }}">{{ $money::format($row['payables_current'], 0) }}</a></td><td class="amt">{{ $money::format($row['cash_balance'], 0) }}</td><td><a class="btn btn-ghost" href="{{ route('director.projects.show', [$row['project'], 'range' => $range['name'], 'from' => $range['from']->toDateString(), 'to' => $range['to']->toDateString()]) }}">View Analytics</a></td></tr>
  @empty<tr><td colspan="12">No projects are registered.</td></tr>@endforelse
  </tbody></table></div>
</section>

<section class="panel attention-panel"><h3>Receivable Attention</h3><div class="attention-content"><div><strong>{{ $report['summary']['overdue_invoice_count'] }}</strong><span> overdue invoices</span></div><div><strong>TSh {{ $money::format($report['summary']['overdue_receivables']) }}</strong><span> overdue receivables</span></div><a class="btn btn-ghost" href="{{ route('director.receivables.index') }}">Review Receivables</a></div></section>
<style>
.director-actions{display:flex;gap:8px;flex-wrap:wrap}.director-filter .row3{max-width:760px}.director-filter .form-context{margin:2px 0 0}.director-tiles .tile{min-width:0}.director-tiles .value{font-size:19px}.director-tiles a,.section-heading a{color:var(--primary-dark);font-weight:600}.director-charts{grid-template-columns:1.35fr 1fr}.chart-project{border-top:1px solid var(--line);padding:10px 0}.chart-project-name{font-weight:600;margin-bottom:6px}.chart-line{display:grid;grid-template-columns:70px minmax(45px,1fr) 112px;align-items:center;gap:8px;margin:6px 0;font-size:11px}.chart-single{grid-template-columns:145px minmax(40px,1fr) 96px}.chart-line b{text-align:right;font-variant-numeric:tabular-nums;font-weight:500}.chart-track{height:8px;background:var(--surface-2);border-radius:6px;overflow:hidden}.chart-track i{display:block;height:100%;min-width:0;background:#2490EF;border-radius:6px}.chart-subheading{margin-top:22px!important}.cash-chart{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px}.cash-month{border:1px solid var(--line);border-radius:8px;padding:12px}.cash-month strong{display:block;margin-bottom:9px}.cash-month>div{display:grid;grid-template-columns:24px minmax(30px,1fr) 70px;gap:6px;align-items:center;margin:7px 0}.cash-month b{text-align:right;font-size:11px}.cash-month>small{font-size:10.5px;color:var(--ink-dim)}.section-heading{display:flex;justify-content:space-between;align-items:start;gap:12px}.director-portfolio{min-width:1110px}.director-portfolio td small{display:block;color:var(--ink-dim);font-size:10px;margin-top:3px}.director-portfolio .badge{margin-top:5px}.attention-panel{margin-top:14px}.attention-content{display:flex;align-items:center;gap:22px;flex-wrap:wrap}.attention-content div{font-size:12px}.attention-content strong{font-size:16px}.attention-content span{color:var(--ink-dim)}@media(max-width:700px){.director-charts{grid-template-columns:1fr}.chart-single{grid-template-columns:110px minmax(30px,1fr) 80px}.chart-line{grid-template-columns:58px minmax(30px,1fr) 90px}.section-heading{display:block}.director-actions{width:100%}}
</style>
@endsection