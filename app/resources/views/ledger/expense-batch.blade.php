@extends('layouts.app')
@section('title', $project->name.' · Record Expense')
@section('content')
@include('partials.ledger.styles')
@php
  $categories = \App\Services\LedgerAnalyticsService::CATEGORIES;
  $colors = \App\Services\LedgerAnalyticsService::CATEGORY_COLORS;
  $leafPhases = $phases->where('leaf', true);
  $defaultPhase = old('default_phase', request('phase', $leafPhases->count() === 1 ? $leafPhases->first()['phase']->id : ''));
  $defaultCategory = $project->is_company_overheads ? \App\Services\ExpenseLedgerService::OVERHEADS_CATEGORY : old('default_category', request('worker') ? 'labour_subcontracts' : 'materials');
  $oldRows = array_values(old('rows', [[]]));
  // A failed save of one date's rows also brings back the rows that were kept aside for later.
  $keptRows = json_decode((string) old('keep_json', '[]'), true);
  if (is_array($keptRows) && $keptRows !== []) { $oldRows = array_merge($oldRows, array_values(array_filter($keptRows, 'is_array'))); }
  $config = [
      'suggestions' => $suggestions,
      'subcategories' => $subcategories->unique('code')->map(fn ($s) => ['code' => $s->code, 'label' => $s->label, 'parent' => $s->parent_code])->values(),
      'units' => $units->map(fn ($u) => ['code' => $u->code, 'label' => $u->label])->values(),
      'unitsFirst' => [
          'materials' => ['bag', 'pc', 'item', 'kg', 'tonne', 'm', 'm2', 'm3', 'l', 'gal', 'roll', 'sheet', 'box', 'pkt', 'bkt', 'pairs', 'bundle', 'no', 'ls'],
          'labour_subcontracts' => ['ls', 'day', 'worker_day', 'week', 'month', 'hour', 'm2', 'm', 'no'],
          'logistics_equipment' => ['trip', 'load', 'day', 'hour', 'km', 'l', 'ls'],
          'preliminaries_overheads' => ['ls', 'month', 'week', 'day', 'l', 'no', 'person'],
      ],
      'placeholders' => [
          'materials' => 'e.g. Cement', 'labour_subcontracts' => 'e.g. Fundi labour', 'logistics_equipment' => 'e.g. Transport', 'preliminaries_overheads' => 'e.g. Water',
      ],
      'defaultUnit' => ['materials' => 'pc', 'labour_subcontracts' => 'ls', 'logistics_equipment' => 'trip', 'preliminaries_overheads' => 'ls'],
      'workers' => $workers->map(fn ($w) => ['id' => $w->id, 'name' => $w->fundi_subcontractor.((float) $w->agreed_value > 0 ? ' · contract '.\App\Support\Money::format($w->agreed_value) : '')])->values(),
      'categories' => $categories,
      'colors' => $colors,
      'presetWorker' => request('worker'),
      'phaseWord' => $project->is_company_overheads ? 'Cost group' : 'Phase',
      'phases' => $phases->mapWithKeys(fn ($node) => [$node['phase']->id => $node['label']]),
      'unitLabels' => $units->pluck('label', 'code'),
      // An unsubmitted form saved earlier (any device) is restored, unless the page is showing a failed save.
      'draft' => (($draft ?? null) && ! old('rows')) ? $draft->data : null,
      'draftUrl' => route('ledger.draft.save', $project),
      'reviewUrl' => route('ledger.draft.review', $project),
      'applyUrl' => route('ledger.sheet-changes.apply', [$project, '__ID__']),
      'undoUrl' => route('ledger.sheet-changes.undo', [$project, '__ID__']),
      'today' => now()->toDateString(),
  ];
  $restoring = ($draft ?? null) && ! old('rows');
@endphp
<style>
  .rb-head{display:grid;grid-template-columns:150px 1fr 1fr 1.2fr;gap:12px;align-items:end}
  .rb-hint{font-size:12px;color:var(--ink-dim);margin:8px 0 0}
  .rb-more-head{background:none;border:0;padding:8px 0 0;color:var(--primary-dark);font:inherit;font-size:13.5px;font-weight:600;cursor:pointer}
  .rb-head.rb-head-more{grid-template-columns:1fr 2fr;margin-top:8px}
  .rb-head.rb-head-more[hidden]{display:none}
  @media(max-width:900px){.rb-head.rb-head-more{grid-template-columns:1fr}}
  .rb-draft{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;background:var(--primary-soft);border:1px solid var(--primary);border-radius:10px;padding:10px 14px;margin-bottom:12px;font-size:13.5px}
  .rb-draft .btn{min-height:36px}
  .rb-saved{font-size:12.5px;color:var(--ink-dim);align-self:center}
  .rb-table tr[hidden]{display:none!important}
  .rv-panel{margin-bottom:12px}
  .rv-head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
  .rv-head h3{margin:0;font-size:15px}
  .rv-sum{font-size:13.5px;color:var(--ink-soft);margin:4px 0 0}
  .rv-groups{display:flex;flex-direction:column;gap:6px;margin-top:10px}
  .rv-group{display:flex;justify-content:space-between;align-items:center;gap:10px;text-align:left;width:100%;padding:8px 12px;border-radius:8px;border:1px solid var(--warn-line);background:var(--warn-soft);color:var(--warn-ink);font:inherit;font-size:13.5px;cursor:pointer}
  .rv-group.error{border-color:var(--danger-line);background:var(--danger-soft);color:var(--danger-ink)}
  .rv-group.on{outline:2px solid var(--primary);outline-offset:1px}
  .rv-group b{font-weight:600}
  .rv-showing{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:10px;font-size:13px;color:var(--ink-soft)}
  .rb-table tr.rv-err td:first-child{box-shadow:inset 3px 0 0 var(--danger)}
  .rb-table tr.rv-warn td:first-child{box-shadow:inset 3px 0 0 var(--warn)}
  .sc-list{display:flex;flex-direction:column;gap:8px;margin-top:12px}
  .sc-list h4{margin:4px 0 0;font-size:14px}
  .sc-item{border:1px solid var(--line);border-radius:8px;padding:10px 12px;font-size:13.5px}
  .sc-item ul{margin:6px 0;padding-left:18px}
  .sc-item .sc-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}
  .sc-item .sc-bad{color:var(--danger-ink)}
  .sc-item small{color:var(--ink-dim)}
  .rv-note{display:flex;flex-direction:column;gap:2px;margin-top:5px;font-size:12px;line-height:1.35}
  .rv-note .rv-error{color:var(--danger-ink)}
  .rv-note .rv-warning{color:var(--warn-ink)}
  .rb-filter{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 14px;border-bottom:1px solid var(--line);background:var(--primary-soft);font-size:13.5px}
  .rb-filter[hidden]{display:none}
  .rb-filter select{padding:8px 10px;border:1px solid var(--line);border-radius:8px;font:inherit;background:var(--input-bg);color:var(--ink);min-height:40px}
  .rb-filter-note{color:var(--ink-dim)}
  .rb-apply{background:none;border:0;padding:6px 0 0;color:var(--primary-dark);font:inherit;font-size:13px;font-weight:600;cursor:pointer;text-align:left}
  /* Preview before saving */
  .pv-dialog{border:0;border-radius:16px;padding:0;width:min(720px,calc(100vw - 24px));max-height:calc(100vh - 40px);background:var(--surface);color:var(--ink);box-shadow:0 20px 60px rgba(0,0,0,.3)}
  .pv-dialog::backdrop{background:rgba(10,16,24,.55)}
  .pv-head{padding:16px 18px;border-bottom:1px solid var(--line);position:sticky;top:0;background:var(--surface);z-index:1}
  .pv-head h3{margin:0 0 4px;font-size:17px}.pv-head p{margin:0;font-size:13px;color:var(--ink-dim)}
  .pv-body{padding:6px 18px}
  .pv-item{display:flex;justify-content:space-between;gap:12px;padding:11px 0;border-bottom:1px solid var(--line)}
  .pv-item b{display:block;font-weight:600}.pv-item small{display:block;color:var(--ink-dim);font-size:12.5px;margin-top:2px}
  .pv-item .amt{font-weight:700;white-space:nowrap;text-align:right}
  .pv-item.bad .amt{color:var(--danger-ink)}
  .pv-warn{background:var(--warn-soft,#fff4e0);color:var(--warn-ink,#8a5a00);border-radius:10px;padding:9px 12px;font-size:13px;margin:10px 0}
  .pv-foot{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:14px 18px;border-top:1px solid var(--line);position:sticky;bottom:0;background:var(--surface)}
  .pv-total{font-size:17px;font-weight:700}
  @media(max-width:760px){.pv-dialog{width:100vw;max-width:100vw;max-height:100dvh;height:100dvh;border-radius:0;margin:0}.pv-foot .btn{flex:1;justify-content:center;min-height:48px}.pv-foot .pv-total{width:100%;text-align:center}}
  .rb-table{width:100%;border-collapse:separate;border-spacing:0;font-size:12.5px;min-width:1450px}
  .rb-table tr.own-date [data-f=date]{border-color:var(--primary);background:var(--primary-soft)}
  .rb-table th{background:var(--head);color:var(--head-ink);font-size:11px;font-weight:600;text-align:left;padding:8px 6px;white-space:nowrap}
  .rb-table td{padding:6px;border-bottom:1px solid var(--line);vertical-align:top}
  .rb-table input,.rb-table select{width:100%;padding:7px 8px;border:1px solid var(--line);border-radius:7px;font:inherit;font-size:12.5px;background:var(--input-bg);color:var(--ink)}
  .rb-table input:focus,.rb-table select:focus{outline:2px solid var(--primary-soft);border-color:var(--primary)}
  .rb-table .num{text-align:right}
  .rb-table td.idx{color:var(--ink-dim);font-weight:600;padding-top:13px;width:26px}
  .rb-table tr.labour td{background:var(--row-labour)}.rb-table tr.logistics td{background:var(--row-logistics)}.rb-table tr.prelims td{background:var(--row-prelims)}
  .rb-cat{border-left:4px solid var(--line)!important}
  .rb-remove{background:none;border:0;color:var(--danger-ink);font-size:16px;cursor:pointer;padding:6px}
  .rb-foot{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-top:10px}
  .rb-total{font-size:16px;font-weight:700}
  @media(max-width:900px){.rb-head{grid-template-columns:1fr}}
  .rb-more-toggle{display:none}
  /* Company overheads: costs are organised by cost group only (no category or subcategory). */
  .ovh-form .rb-cat-th,.ovh-form .rb-sub-th,.ovh-form .rb-table td.rb-catcell,.ovh-form .rb-table td.rb-subcell{display:none!important}
  .ovh-form .rb-head{grid-template-columns:150px 1fr 1.2fr}
  @media(max-width:760px){.ovh-form .rb-table td.rb-phasecell{display:block;grid-column:1/-1}.ovh-form .rb-head{grid-template-columns:1fr}}
  .rb-remove-label{display:none}
  @media(max-width:760px){.rb-remove-label{display:inline}}
  @media(max-width:760px){
    .rb-table{min-width:0;font-size:15px}
    .rb-table thead{display:none}
    .rb-table,.rb-table tbody{display:block}
    .rb-table tr{display:grid;grid-template-columns:1fr 1fr;gap:10px 10px;padding:14px;margin:10px;border:1px solid var(--line);border-radius:14px;background:var(--surface);position:relative}
    .rb-table tr.labour{background:var(--row-labour)}.rb-table tr.logistics{background:var(--row-logistics)}.rb-table tr.prelims{background:var(--row-prelims)}
    .rb-table tr td{display:block;border:0;padding:0;background:transparent!important}
    .rb-table td::before{content:attr(data-label);display:block;font-size:12px;font-weight:600;color:var(--ink-dim);margin-bottom:4px}
    .rb-table td.idx{grid-column:1/-1;width:auto;padding:0;font-size:14px;font-weight:700;color:var(--ink)}.rb-table td.idx::before{content:'Item #';display:inline!important;font-size:14px;font-weight:700;color:var(--ink);margin:0}
    .rb-table td.rb-item,.rb-table td.rb-catcell,.rb-table td.rb-del{grid-column:1/-1}
    .rb-table td.rb-more{display:none;grid-column:1/-1}
    .rb-table tr.open td.rb-more{display:block}
    .rb-table tr.own-date td.rb-datecell{display:block}
    .rb-cat{border-left:0!important}
    .rb-table input,.rb-table select{font-size:16px;padding:10px 12px;min-height:44px;border-radius:10px}
    .rb-table td.rb-del::before{content:none}
    .rb-table td.rb-del{display:flex;justify-content:space-between;align-items:center}
    .rb-more-toggle{display:inline-flex;background:none;border:0;color:var(--primary-dark);font:inherit;font-size:14px;font-weight:600;padding:6px 0;cursor:pointer}
    .rb-remove{font-size:14px;font-weight:600}
    .rb-foot{flex-direction:column;align-items:stretch}
    .rb-foot .btn{flex:1;justify-content:center;min-height:44px}
    .rb-total{text-align:center}
    .rb-actions{display:grid!important;grid-template-columns:1fr 1fr}
    .rb-actions .btn{min-height:48px;font-size:16px;justify-content:center}
    .rb-actions .rb-another{grid-column:1/-1}
  }
</style>

<div class="lg-head">
  <div><h2>Record Expense</h2><p>{{ $project->name }} · add one or many items. Each row follows the date{{ $project->is_company_overheads ? ' and cost group' : ', category and phase' }} chosen above unless you change it on the row.</p></div>
  <div class="lg-actions">@if($entryMode ?? false)@if($canChangeProject ?? true)<a class="btn btn-ghost" href="{{ route('entry.index') }}">Change project</a>@endif @else<a class="btn btn-ghost" href="{{ route('ledger.expenses', $project) }}">Back to Expenditure</a>@endif</div>
</div>
@if ($errors->any())<div class="flash error"><ul style="margin:0;padding-left:20px;">@foreach(collect($errors->all())->unique()->take(12) as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if($leafPhases->isEmpty())<div class="flash error">This project has no phases yet. @if($entryMode ?? false)Ask the Director to add them.@else The Director adds them under <a href="{{ route('ledger.structure', $project) }}">Phases &amp; Budget</a>.@endif</div>@endif

@if($restoring)
  <div class="rb-draft" id="draftBar"><span><b>Unfinished entry restored</b> · {{ $draft->item_count }} item{{ $draft->item_count === 1 ? '' : 's' }}, last saved {{ $draft->updated_at->isToday() ? 'today at '.$draft->updated_at->format('H:i') : $draft->updated_at->format('d M Y, H:i') }}. Continue where you left off, or start again.</span>
    <form method="POST" action="{{ route('ledger.draft.discard', $project) }}" onsubmit="return confirm('Discard this unfinished entry? The items in it will be removed.');">@csrf @method('DELETE')<button class="btn btn-ghost" type="submit">Discard draft</button></form></div>
@endif
<form method="POST" action="{{ route('ledger.expenses.store', $project) }}" id="batchForm" @class(['ovh-form' => $project->is_company_overheads])>@csrf
  <section class="lg-panel" style="margin-bottom:12px">
    <div class="rb-head">
      <div class="field" style="margin:0"><label for="expense_date">Date <span class="req">*</span></label><input type="date" id="expense_date" name="expense_date" value="{{ old('expense_date', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required></div>
      <div class="field" style="margin:0" @if($project->is_company_overheads) hidden @endif><label for="default_category">Main category <span class="req">*</span></label>
        <select id="default_category" name="default_category">@foreach($categories as $code => $label)<option value="{{ $code }}" @selected($defaultCategory === $code)>{{ $label }}</option>@endforeach</select></div>
      <div class="field" style="margin:0"><label for="default_phase">{{ $project->is_company_overheads ? 'Cost group' : 'Phase' }} <span class="req">*</span></label>
        <select id="default_phase" name="default_phase"><option value="">Choose…</option>@foreach($phases as $node)<option value="{{ $node['phase']->id }}" @selected((string) $defaultPhase === (string) $node['phase']->id) @disabled(! $node['leaf'])>{{ str_repeat('— ', $node['depth'] - 1) }}{{ $node['label'] }}{{ $node['leaf'] ? '' : ' ▸' }}</option>@endforeach</select>
          <button type="button" class="rb-apply" id="applyPhase">Set on every item ↓</button></div>
      <div class="field" style="margin:0"><label for="default_supplier">Supplier <span style="font-weight:400;color:var(--ink-dim)">(from the invoice)</span></label>
        <input id="default_supplier" name="default_supplier" list="supplierList" maxlength="150" value="{{ old('default_supplier') }}" placeholder="Type a new one or pick a saved one" autocomplete="off"></div>
    </div>
    <button type="button" class="rb-more-head" id="headMoreToggle" aria-expanded="{{ old('default_receipt') || old('default_notes') ? 'true' : 'false' }}">More details (receipt no., notes) ▾</button>
    <div class="rb-head rb-head-more" id="headMore" @unless(old('default_receipt') || old('default_notes')) hidden @endunless>
      <div class="field" style="margin:0"><label for="default_receipt">Receipt / invoice no. <span style="font-weight:400;color:var(--ink-dim)">(optional)</span></label><input id="default_receipt" name="default_receipt" maxlength="255" value="{{ old('default_receipt') }}" autocomplete="off"></div>
      <div class="field" style="margin:0"><label for="default_notes">Notes <span style="font-weight:400;color:var(--ink-dim)">(optional)</span></label><input id="default_notes" name="default_notes" maxlength="2000" value="{{ old('default_notes') }}" autocomplete="off"></div>
    </div>
    <p class="rb-hint">The date, {{ $project->is_company_overheads ? 'cost group' : 'category, phase' }}, supplier, receipt no. and notes above apply to every item below. Change them on a row only if that item is different. New suppliers are saved for next time.</p>
  </section>

  <section class="lg-panel rv-panel" id="reviewPanel" hidden>
    <div class="rv-head"><div><h3>Review</h3><p class="rv-sum" id="reviewSummary"></p></div><button type="button" class="btn btn-ghost" id="reviewAgain">Check again</button></div>
    <div class="rv-groups" id="reviewGroups"></div>
    <div class="sc-list" id="sheetChanges" hidden></div>
    <div class="rv-showing" id="reviewShowing" hidden><span id="reviewShowingText"></span><button type="button" class="btn btn-ghost" id="reviewShowAll">Show all lines</button></div>
  </section>

  <section class="lg-panel" style="padding:0;overflow:hidden">
    <div class="rb-filter" id="dateFilterBar" hidden><label for="dateFilter"><b>Show date</b></label><select id="dateFilter"></select><span class="rb-filter-note" id="dateFilterNote"></span></div>
    <div style="overflow:auto">
      <table class="rb-table">
        <thead><tr><th></th><th style="width:130px">Date</th><th style="width:150px" class="rb-cat-th">Category</th><th style="width:170px">{{ $project->is_company_overheads ? 'Cost group' : 'Phase' }}</th><th style="min-width:200px">Item <span style="opacity:.7">*</span></th><th style="width:160px">Supplier</th><th style="width:150px" class="rb-sub-th">Subcategory</th><th style="width:80px">Qty</th><th style="width:96px">Unit</th><th style="width:120px">Unit cost</th><th style="width:130px">Total</th><th style="width:110px">Receipt no.</th><th style="min-width:150px">Notes</th><th></th></tr></thead>
        <tbody id="rows"></tbody>
      </table>
    </div>
    <div class="rb-foot" style="padding:10px 14px">
      <div style="display:flex;gap:8px"><button type="button" class="btn btn-ghost" id="addRow">+ Add row</button><button type="button" class="btn btn-ghost" id="addFive">+ 5 rows</button></div>
      <div class="rb-total">Total: TSh <span id="grandTotal">0.00</span> <span class="muted" style="display:inline;font-size:12px;color:var(--ink-dim)" id="rowCount"></span></div>
    </div>
  </section>

  <section class="lg-panel" style="margin-top:12px">
    @if(auth()->user()->hasRole('admin'))
      <input type="hidden" name="status" value="paid">
      <p class="form-context" style="margin:0 0 10px">Your entries are recorded as <b>Paid</b> straight away; no confirmation is needed.</p>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <div class="field" style="margin:0"><label for="payment_date">Paid on</label><input type="date" id="payment_date" name="payment_date" value="{{ old('payment_date') }}" max="{{ now()->toDateString() }}" placeholder="Same as the expense date"></div>
        <div class="field" style="margin:0"><label for="payment_method">Method</label><select id="payment_method" name="payment_method"><option value="">Not recorded</option>@foreach($methods as $code => $label)<option value="{{ $code }}" @selected(old('payment_method') === $code)>{{ $label }}</option>@endforeach</select></div>
        <div class="field" style="margin:0"><label for="transaction_reference">Reference</label><input id="transaction_reference" name="transaction_reference" value="{{ old('transaction_reference') }}" maxlength="255"></div>
      </div>
    @elseif($canMarkPaid)
      <div style="display:flex;gap:16px;align-items:end;flex-wrap:wrap">
        <div class="lg-seg"><label><input type="radio" name="status" value="to_confirm" @checked(old('status', 'to_confirm') === 'to_confirm')>To confirm</label><label><input type="radio" name="status" value="paid" @checked(old('status') === 'paid')>Paid</label></div>
        <div id="paidFields" style="display:flex;gap:10px;flex-wrap:wrap">
          <div class="field" style="margin:0"><label for="payment_date">Payment date</label><input type="date" id="payment_date" name="payment_date" value="{{ old('payment_date', now()->toDateString()) }}" max="{{ now()->toDateString() }}"></div>
          <div class="field" style="margin:0"><label for="payment_method">Method</label><select id="payment_method" name="payment_method"><option value="">Not recorded</option>@foreach($methods as $code => $label)<option value="{{ $code }}" @selected(old('payment_method') === $code)>{{ $label }}</option>@endforeach</select></div>
          <div class="field" style="margin:0"><label for="transaction_reference">Reference</label><input id="transaction_reference" name="transaction_reference" value="{{ old('transaction_reference') }}" maxlength="255"></div>
        </div>
      </div>
    @else
      <input type="hidden" name="status" value="to_confirm">
      <p class="form-context" style="margin:0">Lines are saved as <b>To confirm</b>; Accounts confirms the payments.</p>
    @endif
    <div class="rb-actions" style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap"><button class="btn btn-ghost" type="button" id="reviewButton">Review</button><button class="btn btn-ghost" type="button" id="previewButton">Preview</button><button class="btn btn-primary" type="submit" id="saveButton">Save Expenses</button>@unless($entryMode ?? false)<button class="btn btn-ghost rb-another" type="submit" name="after" value="another">Save &amp; Record More</button>@endunless</div>
    <p class="rb-hint" id="draftStatus">Your entry is kept as a draft while you type, so you can continue later on any device.</p>
  </section>
</form>

<dialog class="pv-dialog" id="previewDialog" aria-labelledby="pvTitle">
  <div class="pv-head"><h3 id="pvTitle">Check before saving</h3><p id="pvMeta"></p></div>
  <div class="pv-body" id="pvBody"></div>
  <div class="pv-foot"><div class="pv-total">Total: TSh <span id="pvTotal">0.00</span></div><div style="display:flex;gap:8px;flex:1;justify-content:flex-end"><button type="button" class="btn btn-ghost" id="pvBack">Back to edit</button><button type="button" class="btn btn-primary" id="pvSubmit">Submit</button></div></div>
</dialog>
<datalist id="supplierList">@foreach($suppliers as $supplier)<option value="{{ $supplier }}"></option>@endforeach</datalist>
<datalist id="unitList">@foreach($units as $unit)<option value="{{ $unit->code }}">{{ $unit->label }}</option>@endforeach</datalist>
<script>
(() => {
  const C = @json($config);
  const old = @json(array_values($oldRows));
  const phaseOptions = document.getElementById('default_phase').innerHTML;
  const body = document.getElementById('rows');
  const headCat = document.getElementById('default_category'), headPhase = document.getElementById('default_phase'), headSup = document.getElementById('default_supplier');
  const headDate = document.getElementById('expense_date');
  // A row's date follows the date above unless changed on the row (past data can mix days in one entry).
  const markDate = tr => { const d = tr.querySelector('[data-f=date]'); tr.classList.toggle('own-date', !!d.value && d.value !== headDate.value); };
  const followed = [['date', 'expense_date', headDate], ['sup', 'supplier', headSup], ['rcpt', 'invoice_receipt_no', document.getElementById('default_receipt')], ['note', 'details', document.getElementById('default_notes')]];
  const rowClass = {labour_subcontracts: 'labour', logistics_equipment: 'logistics', preliminaries_overheads: 'prelims', materials: ''};
  let n = 0;
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const listFor = cat => { const id = 'items-' + cat; if (!document.getElementById(id)) { const dl = document.createElement('datalist'); dl.id = id; dl.innerHTML = (C.suggestions[cat] || []).map(v => `<option value="${esc(v)}"></option>`).join(''); document.body.appendChild(dl); } return id; };
  const subOptions = (cat, value) => {
    const own = C.subcategories.filter(s => s.parent === cat), rest = C.subcategories.filter(s => s.parent !== cat);
    const opt = s => `<option value="${s.code}" ${s.code === value ? 'selected' : ''}>${esc(s.label)}</option>`;
    return `<option value="">—</option><optgroup label="${esc(C.categories[cat])}">${own.map(opt).join('')}</optgroup><optgroup label="Other">${rest.map(opt).join('')}</optgroup>`;
  };
  const unitOptions = (cat, value) => {
    const first = C.unitsFirst[cat] || [], byCode = Object.fromEntries(C.units.map(u => [u.code, u]));
    const top = first.filter(c => byCode[c]).map(c => byCode[c]), rest = C.units.filter(u => !first.includes(u.code));
    const opt = u => `<option value="${u.code}" ${u.code === value ? 'selected' : ''}>${esc(u.label)}</option>`;
    const known = C.units.some(u => u.code === value);
    return (value && !known ? `<option value="${esc(value)}" selected>${esc(value)}</option>` : '') + `<optgroup label="Usual">${top.map(opt).join('')}</optgroup><optgroup label="All units">${rest.map(opt).join('')}</optgroup>`;
  };
  const workerOptions = value => `<option value="">— Worker —</option>` + C.workers.map(w => `<option value="${w.id}" ${String(w.id) === String(value) ? 'selected' : ''}>${esc(w.name)}</option>`).join('') + `<option value="__new">+ Add new worker…</option>`;
  // "+ Add new worker…": register a fundi by name (and kind of work) without leaving the form.
  document.addEventListener('change', async e => {
    const select = e.target;
    if (!select.matches || !select.matches('select[name$="[labour_agreement_id]"]') || select.value !== '__new') return;
    const name = (window.prompt('New worker (fundi) name:') || '').trim();
    if (!name) { select.value = ''; return; }
    const work = (window.prompt('Kind of work (optional), e.g. Mason, Plumber:') || '').trim();
    try {
      const response = await fetch({{ Js::from(route('ledger.workers.quick', $project)) }}, {
        method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('#batchForm input[name=_token]').value},
        body: JSON.stringify({fundi_subcontractor: name, scope_of_work: work}),
      });
      if (!response.ok) throw new Error();
      const worker = await response.json();
      if (!C.workers.some(w => String(w.id) === String(worker.id))) C.workers.push({id: worker.id, name: worker.name});
      document.querySelectorAll('select[name$="[labour_agreement_id]"]').forEach(s => { const keep = s === select ? worker.id : s.value; s.innerHTML = workerOptions(keep); });
    } catch (err) {
      window.alert('The worker could not be added. Please try again.');
      select.value = '';
    }
  });

  const applyCategory = (tr, cat, keepValues) => {
    Object.values(rowClass).filter(Boolean).forEach(c => tr.classList.remove(c)); if (rowClass[cat]) tr.classList.add(rowClass[cat]);
    tr.querySelector('.rb-cat').style.borderLeftColor = C.colors[cat];
    const item = tr.querySelector('[data-f=item]');
    item.setAttribute('list', listFor(cat)); item.placeholder = C.placeholders[cat];
    const sub = tr.querySelector('[data-f=sub]'), unit = tr.querySelector('[data-f=unit]');
    sub.innerHTML = subOptions(cat, keepValues ? sub.dataset.value : '');
    unit.innerHTML = unitOptions(cat, keepValues ? unit.dataset.value : C.defaultUnit[cat]);
    tr.querySelector('.worker-wrap').hidden = cat !== 'labour_subcontracts';
  };

  const addRow = (data = {}) => {
    const i = n++;
    const prev = body.lastElementChild;
    const cat = data.main_category || (prev ? prev.querySelector('[data-f=cat]').value : headCat.value);
    // A new empty row follows the row above; a restored line (draft, sheet) without a phase stays blank so the Review flags it.
    const restored = !!(data.item_description || data.unit_cost || data.total_amount);
    const phase = data.project_phase_id || (restored ? '' : (prev ? prev.querySelector('[data-f=phase]').value : headPhase.value));
    const tr = document.createElement('tr');
    tr.innerHTML = `<td class="idx"></td>
      <td class="rb-more rb-datecell" data-label="Date"><input data-f="date" type="date" name="rows[${i}][expense_date]" max="${C.today}"></td>
      <td class="rb-cat rb-catcell" data-label="Category"><select data-f="cat" name="rows[${i}][main_category]">${Object.entries(C.categories).map(([c, l]) => `<option value="${c}">${esc(l)}</option>`).join('')}</select></td>
      <td class="rb-more rb-phasecell" data-label="${C.phaseWord}"><select data-f="phase" name="rows[${i}][project_phase_id]">${phaseOptions}</select></td>
      <td class="rb-item" data-label="Item"><input data-f="item" name="rows[${i}][item_description]" maxlength="255" value="${esc(data.item_description)}" autocomplete="off"><input type="hidden" name="rows[${i}][sheet_key]" value="${esc(data.sheet_key)}"><input type="hidden" name="rows[${i}][sheet_rev]" value="${esc(data.sheet_rev)}">
          <div class="worker-wrap" style="margin-top:5px"><select name="rows[${i}][labour_agreement_id]">${workerOptions(data.labour_agreement_id ?? (i === 0 ? C.presetWorker : ''))}</select></div></td>
      <td class="rb-more rb-supcell" data-label="Supplier"><input data-f="sup" name="rows[${i}][supplier]" list="supplierList" maxlength="150" autocomplete="off"></td>
      <td class="rb-more rb-subcell" data-label="Subcategory"><select data-f="sub" name="rows[${i}][subcategory]" data-value="${esc(data.subcategory)}"></select></td>
      <td data-label="Qty"><input data-f="qty" class="num" type="number" step="0.01" min="0" name="rows[${i}][quantity]" value="${esc(data.quantity ?? '1')}"></td>
      <td data-label="Unit"><select data-f="unit" name="rows[${i}][unit]" data-value="${esc(data.unit)}"></select></td>
      <td data-label="Unit cost (TSh)"><input data-f="cost" class="num" type="text" inputmode="decimal" data-money step="0.01" min="0" name="rows[${i}][unit_cost]" value="${esc(data.unit_cost)}"></td>
      <td data-label="Total (TSh)"><input data-f="total" class="num" type="text" inputmode="decimal" data-money step="0.01" min="0" name="rows[${i}][total_amount]" value="${esc(data.total_amount)}"></td>
      <td class="rb-more" data-label="Receipt no."><input data-f="rcpt" name="rows[${i}][invoice_receipt_no]" maxlength="255"></td>
      <td class="rb-more" data-label="Notes"><input data-f="note" name="rows[${i}][details]" maxlength="2000"></td>
      <td class="rb-del"><button type="button" class="rb-more-toggle">More details ▾</button><button type="button" class="rb-remove" title="Remove row">× <span class="rb-remove-label">Remove</span></button></td>`;
    // Supplier, receipt no. and notes follow the header until changed on the row.
    followed.forEach(([f, key, head]) => {
      const el = tr.querySelector(`[data-f=${f}]`);
      el.value = data[key] ?? head.value; el.placeholder = 'As above';
      el.dataset.follow = (data[key] === undefined || data[key] === head.value) ? '1' : '0';
      el.addEventListener('input', () => { el.dataset.follow = el.value.trim() === head.value.trim() ? '1' : '0'; });
    });
    tr.querySelector('[data-f=date]').addEventListener('input', () => { markDate(tr); refreshFilter(); sum(); });
    // A row added while one date is shown gets that date, so it stays in view.
    if (filterSelect.value && data.expense_date === undefined) { const d = tr.querySelector('[data-f=date]'); d.value = filterSelect.value; d.dataset.follow = d.value === headDate.value ? '1' : '0'; markDate(tr); }
    markDate(tr);
    tr.querySelector('.rb-more-toggle').addEventListener('click', e => { const open = tr.classList.toggle('open'); e.currentTarget.textContent = open ? 'Fewer details ▴' : 'More details ▾'; });
    body.appendChild(tr);
    tr.querySelector('[data-f=cat]').value = cat;
    const phaseSelect = tr.querySelector('[data-f=phase]'); phaseSelect.value = phase; phaseSelect.querySelector('option[value=""]').textContent = C.phaseWord + '…';
    applyCategory(tr, cat, !!(data.subcategory || data.unit));
    let manualTotal = !!data.total_amount && !data.unit_cost;
    const qty = tr.querySelector('[data-f=qty]'), cost = tr.querySelector('[data-f=cost]'), total = tr.querySelector('[data-f=total]');
    const recalc = () => { if (!manualTotal && cost.value !== '') total.value = ((parseFloat(qty.value || '1') || 0) * (parseFloat(cost.value) || 0)).toFixed(2); sum(); };
    qty.addEventListener('input', recalc); cost.addEventListener('input', () => { manualTotal = false; recalc(); });
    total.addEventListener('input', () => { manualTotal = total.value !== ''; sum(); });
    tr.querySelector('[data-f=cat]').addEventListener('change', e => applyCategory(tr, e.target.value, false));
    tr.querySelector('.rb-remove').addEventListener('click', () => { if (body.children.length > 1) { tr.remove(); number(); sum(); } });
    number(); sum();
    return tr;
  };
  const number = () => { [...body.children].forEach((tr, i) => tr.querySelector('.idx').textContent = i + 1); refreshFilter(); };
  const rowData = tr => { const row = {}; tr.querySelectorAll('[name^="rows["]').forEach(el => { const m = el.name.match(/\]\[([a-z_]+)\]$/); if (m && el.value !== '__new') row[m[1]] = el.value; }); return row; };
  // "Show date": with rows from several days, show one day at a time. Only the rows shown are saved; the rest stay in the draft.
  // Review: lines picked from a Review group (null = all lines).
  let reviewPick = null;
  const filterSelect = document.getElementById('dateFilter'), filterBar = document.getElementById('dateFilterBar'), filterNote = document.getElementById('dateFilterNote');
  const rowDate = tr => tr.querySelector('[data-f=date]').value || headDate.value;
  const shownRows = () => [...body.children].filter(tr => !tr.hidden);
  const shortDate = v => new Date(v + 'T00:00').toLocaleDateString('en-GB', {day: '2-digit', month: 'short', year: 'numeric'});
  function refreshFilter() {
    const counts = {};
    [...body.children].forEach(tr => { const d = rowDate(tr); counts[d] = (counts[d] || 0) + 1; });
    const dates = Object.keys(counts).sort();
    let pick = filterSelect.value;
    if (pick && !counts[pick]) pick = '';
    filterSelect.innerHTML = `<option value="">All dates (${body.children.length})</option>` + dates.map(d => `<option value="${d}">${shortDate(d)} (${counts[d]})</option>`).join('');
    filterSelect.value = pick;
    filterBar.hidden = dates.length < 2 && !pick;
    [...body.children].forEach(tr => { tr.hidden = (!!pick && rowDate(tr) !== pick) || (reviewPick !== null && !reviewPick.has(tr)); });
    const hiddenCount = [...body.children].filter(tr => tr.hidden).length, shown = body.children.length - hiddenCount;
    filterNote.textContent = (pick || reviewPick) ? `Only these ${shown} item${shown === 1 ? '' : 's'} are saved; the other ${hiddenCount} stay in your draft.` : 'Pick a date to save one day at a time.';
  }
  filterSelect.addEventListener('change', () => { refreshFilter(); sum(); window.scrollTo({top: filterBar.getBoundingClientRect().top + window.scrollY - 70}); });
  const sum = () => {
    let cents = 0, filled = 0;
    shownRows().forEach(tr => { const v = parseFloat(tr.querySelector('[data-f=total]').value); if (!isNaN(v)) { cents += Math.round(v * 100); } if (tr.querySelector('[data-f=item]').value.trim()) filled++; });
    document.getElementById('grandTotal').textContent = (cents / 100).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('rowCount').textContent = filled ? `· ${filled} item${filled > 1 ? 's' : ''}` : '';
  };
  // Changing the defaults above updates rows that still follow them (rows changed by hand keep their own value).
  headCat.addEventListener('change', () => [...body.children].forEach(tr => { if (!tr.querySelector('[data-f=item]').value.trim()) { tr.querySelector('[data-f=cat]').value = headCat.value; applyCategory(tr, headCat.value, false); } }));
  followed.forEach(([f, , head]) => head.addEventListener('input', () => body.querySelectorAll(`[data-f=${f}]`).forEach(s => { if (s.dataset.follow === '1') s.value = head.value; })));
  headDate.addEventListener('input', () => { [...body.children].forEach(markDate); refreshFilter(); sum(); });
  const headMore = document.getElementById('headMore'), headMoreToggle = document.getElementById('headMoreToggle');
  const syncHeadMore = () => { const open = headMoreToggle.getAttribute('aria-expanded') === 'true'; headMore.hidden = !open; headMoreToggle.textContent = open ? 'Fewer details ▴' : 'More details (receipt no., notes) ▾'; };
  headMoreToggle.addEventListener('click', () => { headMoreToggle.setAttribute('aria-expanded', headMoreToggle.getAttribute('aria-expanded') === 'true' ? 'false' : 'true'); syncHeadMore(); });
  syncHeadMore();
  headPhase.addEventListener('change', () => [...body.children].forEach(tr => { if (!tr.querySelector('[data-f=item]').value.trim()) tr.querySelector('[data-f=phase]').value = headPhase.value; }));
  // Puts the phase chosen above on every row at once (long entries such as past data).
  document.getElementById('applyPhase').addEventListener('click', () => {
    const word = C.phaseWord.toLowerCase();
    if (!headPhase.value) { alert(`Choose the ${word} above first.`); headPhase.focus(); return; }
    const selects = shownRows().map(tr => tr.querySelector('[data-f=phase]'));
    const other = selects.filter(s => s.value && s.value !== headPhase.value).length;
    if (!confirm(`Set ${word} "${C.phases[headPhase.value]}" on all ${selects.length}${filterSelect.value ? ' shown' : ''} item${selects.length === 1 ? '' : 's'}?` + (other ? `
${other} already ${other === 1 ? 'has' : 'have'} a different ${word} and will be changed.` : ''))) return;
    selects.forEach(s => s.value = headPhase.value);
    queueDraft();
  });
  document.getElementById('addRow').addEventListener('click', () => addRow().querySelector('[data-f=item]').focus());
  document.getElementById('addFive').addEventListener('click', () => { for (let k = 0; k < 5; k++) addRow(); });
  if (C.draft) {
    const h = C.draft.header || {};
    const put = (id, v) => { const el = document.getElementById(id); if (el && v !== undefined && v !== null && v !== '') el.value = v; };
    ['expense_date', 'default_category', 'default_phase', 'default_supplier', 'default_receipt', 'default_notes', 'payment_date', 'payment_method', 'transaction_reference'].forEach(id => put(id, h[id]));
    if (h.default_receipt || h.default_notes) { headMoreToggle.setAttribute('aria-expanded', 'true'); syncHeadMore(); }
    (C.draft.rows || []).forEach(row => addRow(row));
  } else {
    old.forEach(row => addRow(row));
  }
  if (!body.children.length) addRow();
  // Enter in the last row's notes adds a new row instead of submitting.
  body.addEventListener('keydown', e => { if (e.key === 'Enter' && e.target.tagName === 'INPUT') { e.preventDefault(); const tr = e.target.closest('tr'); if (tr === body.lastElementChild) addRow().querySelector('[data-f=item]').focus(); } });
  const paid = document.getElementById('paidFields');
  if (paid) { const sync = () => paid.hidden = document.querySelector('input[name=status]:checked')?.value !== 'paid'; document.querySelectorAll('input[name=status]').forEach(r => r.addEventListener('change', sync)); sync(); }
  // Draft: the form is saved to the account as you type, and removed once the expenses are saved.
  const token = document.querySelector('#batchForm input[name=_token]').value, statusLine = document.getElementById('draftStatus');
  let draftTimer = null, submitting = false, lastSent = '';
  // Lines from the Google sheet this page has loaded; lines the sheet sync adds later are kept by the server.
  const knownKeys = (C.draft?.rows || []).map(r => r.sheet_key).filter(Boolean);
  const collect = () => {
    const header = {};
    ['expense_date', 'default_category', 'default_phase', 'default_supplier', 'default_receipt', 'default_notes', 'payment_date', 'payment_method', 'transaction_reference'].forEach(id => { const el = document.getElementById(id); if (el) header[id] = el.value; });
    return JSON.stringify({header, rows: [...body.children].map(rowData), known_keys: knownKeys});
  };
  const saveDraft = (keepalive = false) => {
    if (submitting) return;
    const payload = collect();
    if (payload === lastSent) return;
    lastSent = payload;
    fetch(C.draftUrl, {method: 'POST', keepalive, headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token}, body: payload})
      .then(r => r.ok ? r.json() : Promise.reject())
      .then(d => { statusLine.textContent = d.arrived ? `${d.arrived} new line(s) arrived from the Google Sheet and are kept in your draft. Reload the page to see them.` : d.saved ? `Draft saved at ${d.at}. You can continue later on any device.` : 'Your entry is kept as a draft while you type, so you can continue later on any device.'; })
      .catch(() => { lastSent = ''; statusLine.textContent = 'Draft not saved (no connection?). Keep the page open and save when you can.'; });
  };
  const queueDraft = () => { clearTimeout(draftTimer); draftTimer = setTimeout(() => { saveDraft(); if (reviewed) refreshReview(); }, 1500); };
  ['input', 'change'].forEach(type => document.getElementById('batchForm').addEventListener(type, queueDraft));
  body.addEventListener('click', e => { if (e.target.closest('.rb-remove')) queueDraft(); });
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') { clearTimeout(draftTimer); saveDraft(true); } });
  document.getElementById('batchForm').addEventListener('submit', e => { submitting = true; clearTimeout(draftTimer); setTimeout(() => { if (e.defaultPrevented) submitting = false; }, 0); });
  lastSent = C.draft ? collect() : '';
  // Review: the same checks as the save, grouped by problem. Clicking a group shows only those lines.
  const reviewPanel = document.getElementById('reviewPanel'), reviewGroups = document.getElementById('reviewGroups'), reviewShowing = document.getElementById('reviewShowing');
  let reviewed = false, pickedCode = null, reviewAsked = false;
  const runReview = trs => fetch(C.reviewUrl, {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token},
    body: JSON.stringify({expense_date: headDate.value, rows: trs.map(rowData)})}).then(r => r.ok ? r.json() : Promise.reject());
  const showPick = () => {
    reviewShowing.hidden = reviewPick === null;
    if (reviewPick) document.getElementById('reviewShowingText').textContent = `Showing ${reviewPick.size} line${reviewPick.size === 1 ? '' : 's'}: ${reviewGroups.querySelector('.rv-group.on b')?.textContent || ''}. Only the lines shown are saved; the others stay in your draft.`;
    refreshFilter(); sum();
  };
  const showReview = (result, trs) => {
    reviewed = true;
    body.querySelectorAll('.rv-note').forEach(n => n.remove());
    [...body.children].forEach(tr => tr.classList.remove('rv-err', 'rv-warn'));
    const notes = new Map();
    result.groups.forEach(g => g.rows.forEach(r => { const tr = trs[r.index]; if (!tr) return; if (!notes.has(tr)) notes.set(tr, []); notes.get(tr).push([g.severity, `${g.label}: ${r.note}`]); }));
    notes.forEach((list, tr) => {
      tr.classList.add(list.some(([sev]) => sev === 'error') ? 'rv-err' : 'rv-warn');
      const div = document.createElement('div'); div.className = 'rv-note';
      div.innerHTML = list.map(([sev, text]) => `<span class="rv-${sev}">${esc(text)}</span>`).join('');
      tr.querySelector('.rb-item').appendChild(div);
    });
    document.getElementById('reviewSummary').textContent = !result.groups.length ? 'No problems found.'
      : `${result.errors ? `${result.errors} problem${result.errors === 1 ? ' stops' : 's stop'} saving` : 'Nothing stops saving'}${result.warnings ? ` · ${result.warnings} to check` : ''}. Click a group to show only those lines.`;
    if (pickedCode && !result.groups.some(g => g.code === pickedCode)) { pickedCode = null; reviewPick = null; }
    reviewGroups.innerHTML = '';
    result.groups.forEach(g => {
      const button = document.createElement('button'); button.type = 'button';
      button.className = `rv-group ${g.severity}${g.code === pickedCode ? ' on' : ''}`;
      button.innerHTML = `<span><b>${esc(g.label)}</b> · ${g.severity === 'error' ? 'stops saving' : 'check'}</span><span>${g.rows.length} line${g.rows.length === 1 ? '' : 's'}</span>`;
      button.addEventListener('click', () => {
        const off = pickedCode === g.code;
        pickedCode = off ? null : g.code;
        reviewGroups.querySelectorAll('.rv-group').forEach(b => b.classList.toggle('on', b === button && !off));
        reviewPick = off ? null : new Set(g.rows.map(r => trs[r.index]).filter(Boolean));
        showPick();
        if (!off) { const first = [...body.children].find(tr => !tr.hidden); if (first) first.scrollIntoView({block: 'center'}); }
      });
      reviewGroups.appendChild(button);
    });
    showSheetChanges(result.sheet_changes || []);
    if (result.groups.length || (result.sheet_changes || []).length || reviewAsked) reviewPanel.hidden = false;
    showPick();
  };
  // Changes typed in the Google sheet to lines already saved: never applied without a decision here.
  const showSheetChanges = list => {
    const box = document.getElementById('sheetChanges');
    box.hidden = !list.length;
    box.innerHTML = list.length ? `<h4>Changed in the Google Sheet after saving (${list.length})</h4>` : '';
    list.forEach(change => {
      const item = document.createElement('div'); item.className = 'sc-item';
      item.innerHTML = `<b>${esc(change.code)}</b> · ${esc(change.item)} <small>${esc(change.at)}</small>`
        + `<ul>${change.changes.map(c => `<li>${esc(c.field)}: ${esc(c.from)} → <b>${esc(c.to)}</b></li>`).join('') || '<li>No visible difference</li>'}</ul>`
        + (change.problems.length ? `<div class="sc-bad">Fix in the sheet first: ${esc(change.problems.join('; '))}</div>` : '')
        + (change.can_act ? `<div class="sc-actions"><button type="button" class="btn btn-primary" data-act="apply" ${change.problems.length ? 'disabled' : ''}>Apply to the saved line</button><button type="button" class="btn btn-ghost" data-act="undo">Undo in the sheet</button></div>`
          : '<small>Waiting for the Director (or whoever may change this line).</small>');
      item.querySelectorAll('[data-act]').forEach(button => button.addEventListener('click', () => {
        if (button.dataset.act === 'undo' && !confirm('Put the saved values back into the sheet row?')) return;
        button.disabled = true;
        fetch((button.dataset.act === 'apply' ? C.applyUrl : C.undoUrl).replace('__ID__', change.id), {method: 'POST', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': token}})
          .then(r => r.json().then(d => ({ok: r.ok, d})))
          .then(({ok, d}) => { alert(ok ? d.message : (d.message || 'That did not work.')); refreshReview(); })
          .catch(() => { button.disabled = false; alert('No connection. Try again.'); });
      }));
      box.appendChild(item);
    });
  };
  const refreshReview = () => { const trs = [...body.children]; return runReview(trs).then(r => showReview(r, trs)).catch(() => { document.getElementById('reviewSummary').textContent = 'The review could not run (no connection?). Try again.'; reviewPanel.hidden = false; }); };
  document.getElementById('reviewButton').addEventListener('click', () => { reviewAsked = true; refreshReview().then(() => reviewPanel.scrollIntoView({block: 'start'})); });
  document.getElementById('reviewAgain').addEventListener('click', refreshReview);
  document.getElementById('reviewShowAll').addEventListener('click', () => { pickedCode = null; reviewPick = null; reviewGroups.querySelectorAll('.rv-group').forEach(b => b.classList.remove('on')); showPick(); });
  refreshReview();
  // Preview: everything entered, as it will be saved, before submitting.
  const form = document.getElementById('batchForm'), dialog = document.getElementById('previewDialog');
  const fmt = v => (v || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const dateText = v => v ? new Date(v + 'T00:00').toLocaleDateString('en-GB', {day: '2-digit', month: 'short', year: 'numeric'}) : '—';
  document.getElementById('previewButton').addEventListener('click', () => {
    const rows = shownRows().filter(tr => tr.querySelector('[data-f=item]').value.trim() || tr.querySelector('[data-f=total]').value || tr.querySelector('[data-f=cost]').value);
    let cents = 0; const warnings = [];
    const html = rows.map((tr, k) => {
      const v = f => tr.querySelector(`[data-f=${f}]`).value.trim();
      const total = parseFloat(v('total')); if (!isNaN(total)) cents += Math.round(total * 100);
      const unitCode = v('unit'), qty = v('qty') || '1', cost = v('cost');
      const bits = [v('sup') || 'No supplier', C.phases[v('phase')] || (C.phaseWord + ' not chosen')];
      if (!C.phaseWord.startsWith('Cost')) bits.unshift(C.categories[v('cat')]);
      if (v('date') && v('date') !== headDate.value) bits.unshift(dateText(v('date')));
      const worker = tr.querySelector('select[name$="[labour_agreement_id]"]');
      if (v('cat') === 'labour_subcontracts' && worker && worker.value && worker.value !== '__new') bits.push('Worker: ' + worker.selectedOptions[0].textContent.split(' · ')[0]);
      const amount = cost ? `${qty} ${C.unitLabels[unitCode] || unitCode} × ${fmt(parseFloat(cost))}` : `${qty} ${C.unitLabels[unitCode] || unitCode}`;
      const problems = [];
      if (!v('item')) problems.push('no item name');
      if (!v('phase')) problems.push('no ' + C.phaseWord.toLowerCase());
      if (isNaN(total) || total <= 0) problems.push('no amount');
      if (problems.length) warnings.push(`Item ${k + 1}: ${problems.join(', ')}`);
      return `<div class="pv-item ${problems.length ? 'bad' : ''}"><div><b>${k + 1}. ${esc(v('item') || '(no item name)')}</b><small>${esc(bits.join(' · '))}</small><small>${esc(amount)}${tr.querySelector('[name$="[invoice_receipt_no]"]').value.trim() ? ' · Receipt ' + esc(tr.querySelector('[name$="[invoice_receipt_no]"]').value.trim()) : ''}</small></div><div class="amt">${isNaN(total) ? '—' : fmt(total)}</div></div>`;
    }).join('');
    const ownDates = rows.filter(tr => { const d = tr.querySelector('[data-f=date]').value; return d && d !== headDate.value; }).length;
    document.getElementById('pvMeta').textContent = `${filterSelect.value ? dateText(filterSelect.value) : dateText(headDate.value) + (ownDates ? ` (${ownDates} with their own date)` : '')} · ${rows.length} item${rows.length === 1 ? '' : 's'}${headSup.value.trim() ? ' · ' + headSup.value.trim() : ''}`;
    const left = body.children.length - shownRows().length;
    if (left) warnings.unshift(`Only the ${rows.length} item(s) for ${shortDate(filterSelect.value)} are saved now; the other ${left} stay in your draft.`);
    document.getElementById('pvBody').innerHTML = (warnings.length ? `<div class="pv-warn"><b>Please check:</b><br>${warnings.map(esc).join('<br>')}</div>` : '') + (html || '<p class="hint" style="padding:14px 0">Nothing entered yet.</p>');
    document.getElementById('pvTotal').textContent = fmt(cents / 100);
    document.getElementById('pvSubmit').disabled = rows.length === 0;
    dialog.showModal();
  });
  document.getElementById('pvBack').addEventListener('click', () => dialog.close());
  document.getElementById('pvSubmit').addEventListener('click', () => { dialog.close(); form.requestSubmit(document.getElementById('saveButton')); });
  // Phases with subphases (▸) cannot take expenses: block them in each row too.
  document.getElementById('batchForm').addEventListener('submit', e => { const bad = shownRows().find(tr => tr.querySelector('[data-f=item]').value.trim() && !tr.querySelector('[data-f=phase]').value); if (bad) { e.preventDefault(); bad.querySelector('[data-f=phase]').focus(); alert('Choose a phase for every item.'); } });
  // Lines with problems that stop saving (see Review) are caught before sending; the server checks again.
  let reviewPassed = false;
  form.addEventListener('submit', e => {
    if (e.defaultPrevented) return;
    if (reviewPassed) { reviewPassed = false; return; }
    e.preventDefault();
    const trs = shownRows(), submitter = e.submitter;
    const send = () => { reviewPassed = true; form.requestSubmit(submitter && submitter.form === form ? submitter : undefined); };
    runReview(trs).then(result => {
      if (!result.errors) { send(); return; }
      showReview(result, trs);
      reviewPanel.scrollIntoView({block: 'start'});
      alert(`${result.errors} problem${result.errors === 1 ? ' stops' : 's stop'} saving. The lines are marked in red; see Review.`);
    }).catch(send);
  });
  // The rows are sent packed in one field: the server drops form fields past its limit (about 75 rows' worth).
  const rowInputs = () => body.querySelectorAll('[name^="rows["]');
  const unpack = () => { form.querySelectorAll('input[name=rows_json], input[name=keep_json], input[name=known_keys_json]').forEach(el => el.remove()); rowInputs().forEach(el => el.disabled = false); };
  form.addEventListener('submit', e => {
    if (e.defaultPrevented) return;
    unpack();
    [['rows_json', shownRows()], ['keep_json', [...body.children].filter(tr => tr.hidden)]].forEach(([name, rows]) => {
      const field = document.createElement('input'); field.type = 'hidden'; field.name = name; field.value = JSON.stringify(rows.map(rowData));
      form.appendChild(field);
    });
    const known = document.createElement('input'); known.type = 'hidden'; known.name = 'known_keys_json'; known.value = JSON.stringify(knownKeys);
    form.appendChild(known);
    rowInputs().forEach(el => el.disabled = true);
  });
  window.addEventListener('pageshow', unpack);
})();
</script>
@if($entryMode ?? false)@include('entry._today', ['today' => $today])@endif
@endsection
