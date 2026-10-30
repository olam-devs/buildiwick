@extends('layouts.app')
@section('title', $project->name.' · '.($line ? 'Edit '.$line->request_code : 'Record Expense'))
@section('content')
@include('partials.ledger.styles')
@php
  $categories = \App\Services\LedgerAnalyticsService::CATEGORIES;
  $colors = \App\Services\LedgerAnalyticsService::CATEGORY_COLORS;
  $value = fn (string $field, $default = null) => old($field, $line ? match ($field) {
      'expense_date' => $line->requested_at?->toDateString(),
      'details' => $line->notes,
      default => $line->{$field},
  } : $default);
@endphp
<div class="lg-head">
  <div><h2>{{ $line ? 'Edit '.$line->request_code : 'Record Expense' }}</h2><p>{{ $project->name }} · one line per item or payment. Lump sums: quantity 1 and unit LS.</p></div>
  <div class="lg-actions"><a class="btn btn-ghost" href="{{ route('ledger.expenses', $project) }}">Back to Expenditure</a></div>
</div>

@if ($errors->any())<div class="flash error"><ul style="margin:0;padding-left:20px;">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if($phases->isEmpty())<div class="flash error">This project has no phases yet. The Director adds them under <a href="{{ route('ledger.structure', $project) }}">Phases &amp; Budget</a>.</div>@endif

<form method="POST" action="{{ $line ? route('ledger.expenses.update', [$project, $line]) : route('ledger.expenses.store', $project) }}" class="lg-panel lg-form" id="expenseForm">@csrf @if($line) @method('PUT') @endif
  <div class="lg-step" style="margin-top:0"><span>1</span> Main category</div>
  <div class="lg-cat-pick">@foreach($categories as $code => $label)<label><input type="radio" name="main_category" value="{{ $code }}" required @checked($value('main_category', request('worker') ? 'labour_subcontracts' : null) === $code)><i style="width:9px;height:9px;border-radius:50%;background:{{ $colors[$code] }}"></i>{{ $label }}</label>@endforeach</div>

  <div class="lg-step"><span>2</span> Phase and date</div>
  <div class="grid">
    <div class="field" style="grid-column:span 2"><label for="project_phase_id">Phase / subphase <span class="req">*</span></label><select id="project_phase_id" name="project_phase_id" required><option value="">Choose…</option>@foreach($phases as $node)@php($selected = (string) $value('project_phase_id') === (string) $node['phase']->id)<option value="{{ $node['phase']->id }}" @selected($selected) @disabled(! $node['leaf'] && ! $selected)>{{ str_repeat('— ', $node['depth'] - 1) }}{{ $node['label'] }}{{ $node['leaf'] ? '' : ' ▸' }}</option>@endforeach</select>
      <small class="form-context">Phases marked ▸ have subphases. Record the expense on the subphase.</small></div>
    <div class="field"><label for="expense_date">Date <span class="req">*</span></label><input type="date" id="expense_date" name="expense_date" value="{{ $value('expense_date', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required></div>
  </div>

  <div class="lg-step"><span>3</span> Item</div>
  <div class="grid">
    <div class="field" style="grid-column:span 2"><label for="item_description">Material / item name <span class="req">*</span></label><input id="item_description" name="item_description" list="itemSuggestions" maxlength="255" value="{{ $value('item_description') }}" placeholder="e.g. Cement, Sand, Binding wire, Fundi labour" required>
      <datalist id="itemSuggestions">@foreach($suggestions->flatten() as $suggestion)<option value="{{ $suggestion }}"></option>@endforeach</datalist></div>
    <div class="field"><label for="subcategory">Subcategory (optional)</label><select id="subcategory" name="subcategory"><option value="">—</option>@foreach($subcategories->unique('code') as $sub)<option value="{{ $sub->code }}" @selected($value('subcategory') === $sub->code)>{{ $sub->label }}</option>@endforeach</select></div>
    <div class="field" id="workerField" style="grid-column:span 2"><label for="labour_agreement_id">Worker (labour)</label><select id="labour_agreement_id" name="labour_agreement_id"><option value="">— Not a registered worker —</option>@foreach($workers as $worker)<option value="{{ $worker->id }}" @selected((string) $value('labour_agreement_id', request('worker')) === (string) $worker->id)>{{ $worker->fundi_subcontractor }}@if((float) $worker->agreed_value > 0) · contract TSh {{ \App\Support\Money::format($worker->agreed_value) }}@endif</option>@endforeach</select>
      <small class="form-context">Workers are registered under <a href="{{ route('ledger.workers', $project) }}">Workers</a>.</small></div>
    <div class="field" style="grid-column:1/-1"><label for="details">Description / notes</label><input id="details" name="details" maxlength="2000" value="{{ $value('details') }}" placeholder="Extra detail, e.g. size, where it was used"></div>
  </div>

  <div class="lg-step"><span>4</span> Amount</div>
  <div class="grid">
    <div class="field"><label for="quantity">Quantity</label><input type="number" step="0.01" min="0" id="quantity" name="quantity" value="{{ $value('quantity', '1') }}"></div>
    <div class="field"><label for="unit">Unit</label><input id="unit" name="unit" list="unitList" maxlength="30" value="{{ $value('unit', 'ls') }}"><datalist id="unitList">@foreach($units as $unit)<option value="{{ $unit->code }}">{{ $unit->label }}</option>@endforeach</datalist></div>
    <div class="field"><label for="unit_cost">Unit cost (TSh)</label><input type="number" step="0.01" min="0" id="unit_cost" name="unit_cost" value="{{ $value('unit_cost') }}"></div>
    <div class="field"><label for="total_amount">Total (TSh)</label><input type="number" step="0.01" min="0" id="total_amount" name="total_amount" value="{{ $value('total_amount') }}"><small class="form-context" id="totalHint">Calculated from quantity × unit cost.</small></div>
    <div class="field"><label for="invoice_receipt_no">Invoice / receipt no.</label><input id="invoice_receipt_no" name="invoice_receipt_no" maxlength="255" value="{{ $value('invoice_receipt_no') }}"></div>
  </div>

  @unless($line)
  <div class="lg-step"><span>5</span> Status</div>
  @if($canMarkPaid)
    <div class="lg-seg"><label><input type="radio" name="status" value="to_confirm" @checked(old('status', 'to_confirm') === 'to_confirm')>To confirm</label><label><input type="radio" name="status" value="paid" @checked(old('status') === 'paid')>Paid</label></div>
    <div class="grid" id="paidFields" style="margin-top:10px">
      <div class="field"><label for="payment_date">Payment date</label><input type="date" id="payment_date" name="payment_date" value="{{ old('payment_date', now()->toDateString()) }}" max="{{ now()->toDateString() }}"></div>
      <div class="field"><label for="payment_method">Payment method</label><select id="payment_method" name="payment_method"><option value="">Choose…</option>@foreach($methods as $code => $label)<option value="{{ $code }}" @selected(old('payment_method') === $code)>{{ $label }}</option>@endforeach</select></div>
      <div class="field"><label for="transaction_reference">Reference</label><input id="transaction_reference" name="transaction_reference" maxlength="255" value="{{ old('transaction_reference') }}"></div>
    </div>
  @else
    <input type="hidden" name="status" value="to_confirm">
    <p class="form-context">Saved as <b>To confirm</b>. Accounts confirms the payment.</p>
  @endif
  @endunless

  <div style="display:flex;gap:8px;margin-top:18px;flex-wrap:wrap">
    <button class="btn btn-primary" type="submit">{{ $line ? 'Save Changes' : 'Save Expense' }}</button>
    @unless($line)<button class="btn btn-ghost" type="submit" name="after" value="another">Save &amp; Record Another</button>@endunless
  </div>
</form>
<script>
(() => {
  const form = document.getElementById('expenseForm');
  const qty = form.quantity, cost = form.unit_cost, total = form.total_amount;
  let manualTotal = !!total.value && !cost.value;
  const recalc = () => { if (!manualTotal && cost.value !== '') { total.value = ((parseFloat(qty.value || '1') || 0) * (parseFloat(cost.value) || 0)).toFixed(2); } };
  qty.addEventListener('input', recalc); cost.addEventListener('input', () => { manualTotal = false; recalc(); });
  total.addEventListener('input', () => { manualTotal = true; });
  const worker = document.getElementById('workerField');
  const syncCategory = () => { const chosen = form.querySelector('input[name=main_category]:checked'); worker.hidden = !chosen || chosen.value !== 'labour_subcontracts'; };
  form.querySelectorAll('input[name=main_category]').forEach(radio => radio.addEventListener('change', syncCategory)); syncCategory();
  const paid = document.getElementById('paidFields');
  if (paid) { const syncPaid = () => { paid.hidden = form.querySelector('input[name=status]:checked')?.value !== 'paid'; }; form.querySelectorAll('input[name=status]').forEach(r => r.addEventListener('change', syncPaid)); syncPaid(); }
})();
</script>
@endsection
