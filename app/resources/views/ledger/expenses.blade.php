@extends('layouts.app')
@section('title', $project->name.' · Expenditure')
@section('content')
@include('partials.ledger.styles')
@php
  $money = \App\Support\Money::class;
  $categories = \App\Services\LedgerAnalyticsService::CATEGORIES;
  $colors = \App\Services\LedgerAnalyticsService::CATEGORY_COLORS;
  $canConfirm = \App\Services\ExpenseLedgerService::canMarkPaid(auth()->user());
  $statusLabels = ['paid' => 'Paid', 'part_paid' => 'Part paid', 'unpaid' => 'To confirm'];
  $query = fn (array $change) => route('ledger.expenses', array_merge([$project], array_filter(array_merge(request()->except('page'), $change), fn ($v) => $v !== null && $v !== '')));
@endphp
<div class="lg-head">
  <div><h2>Expenditure</h2><p>{{ $project->name }} · every recorded expense, one line each, as in the project tracker.</p></div>
  <div class="lg-actions">
    @can('record-expense', $project)<a class="btn btn-primary" href="{{ route('ledger.expenses.create', $project) }}">+ Record Expense</a>@endcan
    <a class="btn btn-ghost" href="{{ route('ledger.expenses.export', array_merge([$project], request()->except('page'))) }}">Download Excel</a>
  </div>
</div>

@if ($errors->any())<div class="flash error"><ul style="margin:0;padding-left:20px;">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<div class="lg-chips">
  <a class="lg-chip {{ empty($filters['category']) ? 'on' : '' }}" href="{{ $query(['category' => null]) }}">All categories</a>
  @foreach($categories as $code => $label)<a class="lg-chip {{ ($filters['category'] ?? '') === $code ? 'on' : '' }}" href="{{ $query(['category' => $code]) }}"><i style="background:{{ $colors[$code] }}"></i>{{ $label }}</a>@endforeach
</div>

<form method="GET" class="lg-filters">
  @if(!empty($filters['category']))<input type="hidden" name="category" value="{{ $filters['category'] }}">@endif
  <div class="field"><label for="phase">Phase</label><select id="phase" name="phase"><option value="">All phases</option>@foreach($tree->flatten() as $node)<option value="{{ $node['phase']->id }}" @selected((string) ($filters['phase'] ?? '') === (string) $node['phase']->id)>{{ str_repeat('— ', $node['depth'] - 1) }}{{ $node['label'] }}</option>@endforeach</select></div>
  <div class="field"><label for="status">Status</label><select id="status" name="status"><option value="">Any</option><option value="paid" @selected(($filters['status'] ?? '') === 'paid')>Paid</option><option value="to_confirm" @selected(($filters['status'] ?? '') === 'to_confirm')>To confirm</option><option value="part_paid" @selected(($filters['status'] ?? '') === 'part_paid')>Part paid</option></select></div>
  <div class="field"><label for="subcategory">Subcategory</label><select id="subcategory" name="subcategory"><option value="">Any</option>@foreach($subcategories as $code => $label)<option value="{{ $code }}" @selected(($filters['subcategory'] ?? '') === $code)>{{ $label }}</option>@endforeach</select></div>
  @if($workers->isNotEmpty())<div class="field"><label for="worker">Worker</label><select id="worker" name="worker"><option value="">Any</option>@foreach($workers as $id => $name)<option value="{{ $id }}" @selected((string) ($filters['worker'] ?? '') === (string) $id)>{{ $name }}</option>@endforeach</select></div>@endif
  <div class="field" style="min-width:130px"><label for="from">From</label><input type="date" id="from" name="from" value="{{ $filters['from'] ?? '' }}"></div>
  <div class="field" style="min-width:130px"><label for="to">To</label><input type="date" id="to" name="to" value="{{ $filters['to'] ?? '' }}"></div>
  <div class="field" style="min-width:180px;flex:1"><label for="q">Search</label><input id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Item, ID, receipt no."></div>
  <div class="field" style="min-width:120px"><label for="sort">Sort</label><select id="sort" name="sort"><option value="newest">Newest first</option><option value="oldest" @selected(($filters['sort'] ?? '') === 'oldest')>Oldest first</option><option value="amount" @selected(($filters['sort'] ?? '') === 'amount')>Largest amount</option></select></div>
  <button class="btn btn-primary" type="submit">Apply</button>
  @if(array_filter($filters))<a class="btn btn-ghost" href="{{ route('ledger.expenses', $project) }}">Clear</a>@endif
</form>

<div class="lg-cards">
  <div class="lg-card accent"><div class="k">Recorded</div><div class="v">TSh {{ $money::format($totals['recorded']) }}</div><div class="s">{{ number_format($totals['count']) }} lines in this view</div></div>
  <div class="lg-card good"><div class="k">Paid</div><div class="v">TSh {{ $money::format($totals['paid']) }}</div></div>
  <div class="lg-card warn"><div class="k">To confirm</div><div class="v">TSh {{ $money::format($totals['to_confirm']) }}</div></div>
</div>

<div class="lg-scroll">
  <table class="lg-table">
    <thead><tr><th>ID / Date</th><th>Category</th><th>Item / Description</th><th>Phase</th><th class="amt">Qty</th><th class="amt">Unit Cost</th><th class="amt">Total</th><th class="amt">Paid</th><th></th></tr></thead>
    <tbody>
    @forelse($lines as $line)
      <tr title="Recorded by {{ $line->requestedBy?->name ?? '—' }}">
        <td style="white-space:nowrap"><span class="code">{{ $line->request_code }}</span><span class="muted">{{ $line->requested_at?->format('d M Y') }}</span></td>
        <td style="min-width:140px"><span class="lg-cat"><i style="background:{{ $colors[$line->main_category] ?? '#999' }}"></i>{{ $categories[$line->main_category] ?? $line->main_category }}</span>@if($line->subcategory)<span class="muted">{{ $subcategories[$line->subcategory] ?? $line->subcategory }}</span>@endif</td>
        <td style="min-width:200px">{{ $line->item_description }}@if($line->labourAgreement)<span class="muted">Worker: {{ $line->labourAgreement->fundi_subcontractor }}</span>@endif @if($line->notes)<span class="muted" title="{{ $line->notes }}">{{ \Illuminate\Support\Str::limit(\Illuminate\Support\Str::after($line->notes, 'Evidence: '), 48) }}</span>@endif</td>
        <td style="min-width:110px">{{ $tree->path($line->project_phase_id) }}</td>
        <td class="amt">{{ $line->quantity !== null ? rtrim(rtrim($line->quantity, '0'), '.') : '—' }}<span class="muted">{{ $units[$line->unit] ?? $line->unit }}</span></td>
        <td class="amt">{{ $line->unit_cost !== null ? $money::format($line->unit_cost) : '—' }}</td>
        <td class="amt"><b>{{ $money::format($line->total_amount) }}</b></td>
        <td class="amt">{{ $money::format($line->paid_to_date) }}<span style="display:block;margin-top:3px"><span class="lg-pill {{ ($line->ledger_status ?? $line->payment_status) }}">{{ $statusLabels[($line->ledger_status ?? $line->payment_status)] }}</span></span></td>
        <td><div class="lg-row-actions" style="flex-direction:column;align-items:flex-start;gap:3px">
          @if($canConfirm && ($line->ledger_status ?? $line->payment_status) !== 'paid')<button class="lg-link" type="button" data-confirm-url="{{ route('ledger.expenses.confirm', [$project, $line]) }}" data-code="{{ $line->request_code }}" data-item="{{ $line->item_description }}" data-total="{{ $line->total_amount }}" data-outstanding="{{ $line->approved_outstanding_amount }}" data-paid="{{ $line->paid_to_date }}">Confirm</button>@endif
          @if($line->payments->isEmpty())@can('edit-ledger-line', $line)
            <a class="lg-link" href="{{ route('ledger.expenses.edit', [$project, $line]) }}">Edit</a>
            <form method="POST" action="{{ route('ledger.expenses.destroy', [$project, $line]) }}" onsubmit="return confirm('Delete {{ $line->request_code }}?');">@csrf @method('DELETE')<button class="lg-link danger" type="submit">Delete</button></form>
          @endcan @endif
        </div></td>
      </tr>
    @empty
      <tr><td colspan="9" class="lg-empty">No expenses match this view.</td></tr>
    @endforelse
    </tbody>
    @if($lines->total())<tfoot><tr><td colspan="6">Total of {{ number_format($totals['count']) }} lines in this view</td><td class="amt">{{ $money::format($totals['recorded']) }}</td><td class="amt">{{ $money::format($totals['paid']) }}</td><td></td></tr></tfoot>@endif
  </table>
</div>
@if($lines->hasPages())<div class="lg-pager">Page {{ $lines->currentPage() }} of {{ $lines->lastPage() }} @if(!$lines->onFirstPage())<a class="btn btn-ghost" href="{{ $lines->previousPageUrl() }}">← Previous</a>@endif @if($lines->hasMorePages())<a class="btn btn-ghost" href="{{ $lines->nextPageUrl() }}">Next →</a>@endif</div>@endif

<section class="lg-panel" style="margin-top:16px">
  <h3>Spend by phase</h3><p class="hint">Totals for the lines in this view, by phase and main category. Each phase includes its subphases.</p>
  <div class="tablewrap" style="border:0"><table class="lg-table lg-tree"><thead><tr><th>Phase</th>@foreach($categories as $code => $label)<th class="amt">{{ $label }}</th>@endforeach<th class="amt">Total</th><th class="amt">Budget</th></tr></thead><tbody>
    @foreach($phaseRows as $row)
      <tr class="{{ $row['depth'] === 1 ? 'top' : '' }}"><td class="phase d{{ $row['depth'] }}"><a href="{{ $query(['phase' => $row['phase']->id]) }}" style="color:inherit;text-decoration:none">{{ $row['label'] }}</a></td>
        @foreach(array_keys($categories) as $code)<td class="amt">{{ (float) $row['categories'][$code]['recorded'] ? $money::format($row['categories'][$code]['recorded']) : '—' }}</td>@endforeach
        <td class="amt"><b>{{ $money::format($row['recorded']) }}</b></td><td class="amt">{{ (float) $row['budget'] ? $money::format($row['budget']) : '—' }}</td></tr>
    @endforeach
  </tbody></table></div>
</section>

@if($canConfirm)@include('ledger.partials.confirm-dialog')@endif
@endsection
