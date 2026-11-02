@extends('layouts.app')
@section('title', $project->name.' · Record Expense')
@section('content')
@include('partials.ledger.styles')
@php
  $categories = \App\Services\LedgerAnalyticsService::CATEGORIES;
  $colors = \App\Services\LedgerAnalyticsService::CATEGORY_COLORS;
  $leafPhases = $phases->where('leaf', true);
  $defaultPhase = old('default_phase', request('phase', $leafPhases->count() === 1 ? $leafPhases->first()['phase']->id : ''));
  $defaultCategory = old('default_category', request('worker') ? 'labour_subcontracts' : 'materials');
  $oldRows = old('rows', [[]]);
  $config = [
      'suggestions' => $suggestions,
      'subcategories' => $subcategories->unique('code')->map(fn ($s) => ['code' => $s->code, 'label' => $s->label, 'parent' => $s->parent_code])->values(),
      'units' => $units->map(fn ($u) => ['code' => $u->code, 'label' => $u->label])->values(),
      'unitsFirst' => [
          'materials' => ['bag', 'pc', 'kg', 'tonne', 'm', 'm2', 'm3', 'l', 'roll', 'sheet', 'box', 'bundle', 'no', 'ls'],
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
  ];
@endphp
<style>
  .rb-head{display:grid;grid-template-columns:150px 1fr 1fr;gap:12px;align-items:end}
  .rb-table{width:100%;border-collapse:separate;border-spacing:0;font-size:12.5px;min-width:1180px}
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
    .rb-table td.idx{grid-column:1/-1;padding:0;font-size:14px;font-weight:700;color:var(--ink)}.rb-table td.idx::before{content:'Item #';display:inline!important;font-size:14px;font-weight:700;color:var(--ink);margin:0}
    .rb-table td.rb-item,.rb-table td.rb-catcell,.rb-table td.rb-del{grid-column:1/-1}
    .rb-table td.rb-more{display:none;grid-column:1/-1}
    .rb-table tr.open td.rb-more{display:block}
    .rb-cat{border-left:0!important}
    .rb-table input,.rb-table select{font-size:16px;padding:10px 12px;min-height:44px;border-radius:10px}
    .rb-table td.rb-del::before{content:none}
    .rb-table td.rb-del{display:flex;justify-content:space-between;align-items:center}
    .rb-more-toggle{display:inline-flex;background:none;border:0;color:var(--primary-dark);font:inherit;font-size:14px;font-weight:600;padding:6px 0;cursor:pointer}
    .rb-remove{font-size:14px;font-weight:600}
    .rb-foot{flex-direction:column;align-items:stretch}
    .rb-foot .btn{flex:1;justify-content:center;min-height:44px}
    .rb-total{text-align:center}
    #saveButton{width:100%;min-height:48px;font-size:16px;justify-content:center}
  }
</style>

<div class="lg-head">
  <div><h2>Record Expense</h2><p>{{ $project->name }} · add one or many items. Each row follows the date, category and phase chosen above unless you change it on the row.</p></div>
  <div class="lg-actions">@if($entryMode ?? false)@if($canChangeProject ?? true)<a class="btn btn-ghost" href="{{ route('entry.index') }}">Change project</a>@endif @else<a class="btn btn-ghost" href="{{ route('ledger.expenses', $project) }}">Back to Expenditure</a>@endif</div>
</div>
@if ($errors->any())<div class="flash error"><ul style="margin:0;padding-left:20px;">@foreach(collect($errors->all())->unique()->take(12) as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if($leafPhases->isEmpty())<div class="flash error">This project has no phases yet. @if($entryMode ?? false)Ask the Director to add them.@else The Director adds them under <a href="{{ route('ledger.structure', $project) }}">Phases &amp; Budget</a>.@endif</div>@endif

<form method="POST" action="{{ route('ledger.expenses.store', $project) }}" id="batchForm">@csrf
  <section class="lg-panel" style="margin-bottom:12px">
    <div class="rb-head">
      <div class="field" style="margin:0"><label for="expense_date">Date <span class="req">*</span></label><input type="date" id="expense_date" name="expense_date" value="{{ old('expense_date', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required></div>
      <div class="field" style="margin:0"><label for="default_category">Main category <span class="req">*</span></label>
        <select id="default_category" name="default_category">@foreach($categories as $code => $label)<option value="{{ $code }}" @selected($defaultCategory === $code)>{{ $label }}</option>@endforeach</select></div>
      <div class="field" style="margin:0"><label for="default_phase">Phase <span class="req">*</span></label>
        <select id="default_phase" name="default_phase"><option value="">Choose…</option>@foreach($phases as $node)<option value="{{ $node['phase']->id }}" @selected((string) $defaultPhase === (string) $node['phase']->id) @disabled(! $node['leaf'])>{{ str_repeat('— ', $node['depth'] - 1) }}{{ $node['label'] }}{{ $node['leaf'] ? '' : ' ▸' }}</option>@endforeach</select></div>
    </div>
  </section>

  <section class="lg-panel" style="padding:0;overflow:hidden">
    <div style="overflow:auto">
      <table class="rb-table">
        <thead><tr><th></th><th style="width:150px">Category</th><th style="width:170px">Phase</th><th style="min-width:200px">Item <span style="opacity:.7">*</span></th><th style="width:150px">Subcategory</th><th style="width:80px">Qty</th><th style="width:96px">Unit</th><th style="width:120px">Unit cost</th><th style="width:130px">Total</th><th style="width:110px">Receipt no.</th><th style="min-width:150px">Notes</th><th></th></tr></thead>
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
    <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap"><button class="btn btn-primary" type="submit" id="saveButton">Save Expenses</button>@unless($entryMode ?? false)<button class="btn btn-ghost" type="submit" name="after" value="another">Save &amp; Record More</button>@endunless</div>
  </section>
</form>

<datalist id="unitList">@foreach($units as $unit)<option value="{{ $unit->code }}">{{ $unit->label }}</option>@endforeach</datalist>
<script>
(() => {
  const C = @json($config);
  const old = @json(array_values($oldRows));
  const phaseOptions = document.getElementById('default_phase').innerHTML;
  const body = document.getElementById('rows');
  const headCat = document.getElementById('default_category'), headPhase = document.getElementById('default_phase');
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
    tr.className = rowClass[cat] || '';
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
    const phase = data.project_phase_id || (prev ? prev.querySelector('[data-f=phase]').value : headPhase.value);
    const tr = document.createElement('tr');
    tr.innerHTML = `<td class="idx"></td>
      <td class="rb-cat rb-catcell" data-label="Category"><select data-f="cat" name="rows[${i}][main_category]">${Object.entries(C.categories).map(([c, l]) => `<option value="${c}">${esc(l)}</option>`).join('')}</select></td>
      <td class="rb-more" data-label="Phase"><select data-f="phase" name="rows[${i}][project_phase_id]">${phaseOptions}</select></td>
      <td class="rb-item" data-label="Item"><input data-f="item" name="rows[${i}][item_description]" maxlength="255" value="${esc(data.item_description)}" autocomplete="off">
          <div class="worker-wrap" style="margin-top:5px"><select name="rows[${i}][labour_agreement_id]">${workerOptions(data.labour_agreement_id ?? (i === 0 ? C.presetWorker : ''))}</select></div></td>
      <td class="rb-more" data-label="Subcategory"><select data-f="sub" name="rows[${i}][subcategory]" data-value="${esc(data.subcategory)}"></select></td>
      <td data-label="Qty"><input data-f="qty" class="num" type="number" step="0.01" min="0" name="rows[${i}][quantity]" value="${esc(data.quantity ?? '1')}"></td>
      <td data-label="Unit"><select data-f="unit" name="rows[${i}][unit]" data-value="${esc(data.unit)}"></select></td>
      <td data-label="Unit cost (TSh)"><input data-f="cost" class="num" type="number" step="0.01" min="0" name="rows[${i}][unit_cost]" value="${esc(data.unit_cost)}"></td>
      <td data-label="Total (TSh)"><input data-f="total" class="num" type="number" step="0.01" min="0" name="rows[${i}][total_amount]" value="${esc(data.total_amount)}"></td>
      <td class="rb-more" data-label="Receipt no."><input name="rows[${i}][invoice_receipt_no]" maxlength="255" value="${esc(data.invoice_receipt_no)}" placeholder="Optional"></td>
      <td class="rb-more" data-label="Notes"><input name="rows[${i}][details]" maxlength="2000" value="${esc(data.details)}" placeholder="Optional"></td>
      <td class="rb-del"><button type="button" class="rb-more-toggle">More details ▾</button><button type="button" class="rb-remove" title="Remove row">× <span class="rb-remove-label">Remove</span></button></td>`;
    tr.querySelector('.rb-more-toggle').addEventListener('click', e => { const open = tr.classList.toggle('open'); e.currentTarget.textContent = open ? 'Fewer details ▴' : 'More details ▾'; });
    body.appendChild(tr);
    tr.querySelector('[data-f=cat]').value = cat;
    const phaseSelect = tr.querySelector('[data-f=phase]'); phaseSelect.value = phase; phaseSelect.querySelector('option[value=""]').textContent = 'Phase…';
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
  const number = () => [...body.children].forEach((tr, i) => tr.querySelector('.idx').textContent = i + 1);
  const sum = () => {
    let cents = 0, filled = 0;
    [...body.children].forEach(tr => { const v = parseFloat(tr.querySelector('[data-f=total]').value); if (!isNaN(v)) { cents += Math.round(v * 100); } if (tr.querySelector('[data-f=item]').value.trim()) filled++; });
    document.getElementById('grandTotal').textContent = (cents / 100).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('rowCount').textContent = filled ? `· ${filled} item${filled > 1 ? 's' : ''}` : '';
  };
  // Changing the defaults above updates rows that still follow them (rows changed by hand keep their own value).
  headCat.addEventListener('change', () => [...body.children].forEach(tr => { if (!tr.querySelector('[data-f=item]').value.trim()) { tr.querySelector('[data-f=cat]').value = headCat.value; applyCategory(tr, headCat.value, false); } }));
  headPhase.addEventListener('change', () => [...body.children].forEach(tr => { if (!tr.querySelector('[data-f=item]').value.trim()) tr.querySelector('[data-f=phase]').value = headPhase.value; }));
  document.getElementById('addRow').addEventListener('click', () => addRow().querySelector('[data-f=item]').focus());
  document.getElementById('addFive').addEventListener('click', () => { for (let k = 0; k < 5; k++) addRow(); });
  old.forEach(row => addRow(row)); if (!old.length) addRow();
  // Enter in the last row's notes adds a new row instead of submitting.
  body.addEventListener('keydown', e => { if (e.key === 'Enter' && e.target.tagName === 'INPUT') { e.preventDefault(); const tr = e.target.closest('tr'); if (tr === body.lastElementChild) addRow().querySelector('[data-f=item]').focus(); } });
  const paid = document.getElementById('paidFields');
  if (paid) { const sync = () => paid.hidden = document.querySelector('input[name=status]:checked')?.value !== 'paid'; document.querySelectorAll('input[name=status]').forEach(r => r.addEventListener('change', sync)); sync(); }
  // Phases with subphases (▸) cannot take expenses: block them in each row too.
  document.getElementById('batchForm').addEventListener('submit', e => { const bad = [...body.children].find(tr => tr.querySelector('[data-f=item]').value.trim() && !tr.querySelector('[data-f=phase]').value); if (bad) { e.preventDefault(); bad.querySelector('[data-f=phase]').focus(); alert('Choose a phase for every item.'); } });
})();
</script>
@if($entryMode ?? false)@include('entry._today', ['today' => $today])@endif
@endsection
