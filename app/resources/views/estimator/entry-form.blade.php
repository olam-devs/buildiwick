@extends('layouts.app')
@php($editing = $entry !== null)
@section('title', ($editing ? 'Edit Estimate Entry' : 'New Estimate Entry').' · Buildiwick')
@section('content')
<a class="back-link" href="{{ route('estimator.projects.show', $project) }}">&larr; Back to {{ $project->name }}</a>
<div class="topline"><div><h2>{{ $editing ? 'Edit Estimate Entry' : 'New Estimate Entry' }}</h2><p>{{ $project->project_code }} · Phase {{ $phase->phase_order }} — {{ $phase->stage_label }} · {{ $estimate->estimate_code }}</p></div></div>

@if ($errors->any())<div class="flash error"><ul style="margin:0;padding-left:20px;">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<form method="POST" action="{{ $editing ? route('estimator.entries.update', [$project, $phase, $entry]) : route('estimator.entries.store', [$project, $phase]) }}" id="estimateEntryForm">
  @csrf
  @if($editing) @method('PUT') @endif
  <section class="panel form-section">
    <h3>Estimate Line</h3>
    <p class="form-context">Project, working estimate, phase, creator, and creation date are assigned by the system. This workspace supports future phases as well as the current operational phase.</p>
    <input type="hidden" name="work_area" value="{{ old('work_area', $entry?->work_area) }}">
    <input type="hidden" name="main_category" value="{{ old('main_category', $entry?->main_category ?? 'materials') }}">
    <input type="hidden" name="subcategory" value="{{ old('subcategory', $entry?->subcategory) }}">
    <div class="row2">
      <div class="field"><label for="work_section">Subphase / Section</label><input name="work_section" id="work_section" value="{{ old('work_section', $entry?->work_section) }}" maxlength="255" placeholder="e.g. 3.1 Excavation and Earthwork"></div>
      <div class="field"><label for="item_reference">Item Ref.</label><input name="item_reference" id="item_reference" value="{{ old('item_reference', $entry?->item_reference) }}" maxlength="100" placeholder="e.g. 2.3.1"></div>
    </div>
    <div class="field"><label for="item_description">Item / Description <span class="req">*</span></label><input name="item_description" id="item_description" value="{{ old('item_description', $entry?->item_description) }}" maxlength="1000" required></div>
    <div class="row2">
      <div class="field"><label for="notes">Notes</label><input name="notes" id="notes" value="{{ old('notes', $entry?->notes) }}" maxlength="2000"></div>
    </div>
  </section>

  <section class="panel form-section">
    <h3>Pricing and Markup</h3>
    <div class="row3">
      <div class="field"><label for="quantity">Quantity</label><input type="number" min="0" step="0.01" name="quantity" id="quantity" value="{{ old('quantity', $entry?->quantity ?? '1.00') }}"><div class="field-err">Leave blank or use 1 for a genuine lump-sum line.</div></div>
      <div class="field"><label for="unit">Unit</label><select name="unit" id="unit"><option value="">No unit</option>@foreach($lookups['units'] as $item)<option value="{{ $item->code }}" @selected(old('unit', $entry?->unit ?? 'ls') === $item->code)>{{ $item->label }}</option>@endforeach</select><div class="field-err">Suggested units move to the top by category; all valid units remain available.</div></div>
      <div class="field"><label for="base_unit_cost">Base Unit Cost (TSh) <span class="req">*</span></label><input type="number" min="0" step="0.01" name="base_unit_cost" id="base_unit_cost" value="{{ old('base_unit_cost', $entry?->base_unit_cost) }}" required></div>
    </div>
    <div class="field"><label for="markup_percent">Markup % <span class="req">*</span></label><input type="number" min="0" max="1000" step="0.0001" name="markup_percent" id="markup_percent" value="{{ old('markup_percent', $entry?->markup_percent ?? '0.0000') }}" required></div>
    <div class="tablewrap"><table class="calculation-preview" style="min-width:500px;"><thead><tr><th>Calculated value</th><th>Preview (TSh)</th></tr></thead><tbody>
      <tr><td>Client Unit Price</td><td class="amt"><output id="client_unit_price">—</output></td></tr>
      <tr><td>Base Line Total</td><td class="amt"><output id="base_line_total">—</output></td></tr>
      <tr><td>Markup Amount</td><td class="amt"><output id="markup_amount">—</output></td></tr>
      <tr><td>Client Line Total</td><td class="amt"><output id="client_line_total">—</output></td></tr>
    </tbody></table></div>
    <p class="field-err">Preview uses integer-cent arithmetic in the browser. Saved and reported amounts are recalculated authoritatively on the server using fixed-point decimal arithmetic.</p>
  </section>
  @if($estimate->version > 1)<section class="panel form-section"><h3>Change Justification</h3><div class="field"><label for="edit_reason">Why is this approved estimate being changed? <span class="req">*</span></label><textarea id="edit_reason" name="edit_reason" maxlength="2000" required>{{ old('edit_reason') }}</textarea><div class="field-err">This reason will be kept in the project edit log and shown to the Director.</div></div></section>@endif
  <div style="display:flex;justify-content:flex-end;gap:8px;margin:16px 0 32px;"><a class="btn btn-ghost" href="{{ route('estimator.projects.show', $project) }}">Cancel</a><button class="btn btn-primary" type="submit">{{ $editing ? 'Save Estimate Entry' : 'Add Estimate Entry' }}</button></div>
</form>

<script>
(function(){
  function scaled(value, digits){
    const parts = String(value || '0').split('.');
    const whole = BigInt(parts[0] || '0');
    const fraction = (parts[1] || '').padEnd(digits, '0').slice(0, digits);
    return whole * (10n ** BigInt(digits)) + BigInt(fraction || '0');
  }
  function formatCents(value){
    const sign = value < 0n ? '-' : '';
    const absolute = value < 0n ? -value : value;
    return sign + (absolute / 100n).toLocaleString() + '.' + String(absolute % 100n).padStart(2, '0');
  }
  function updatePreview(){
    const base = scaled(document.getElementById('base_unit_cost').value, 2);
    const quantity = scaled(document.getElementById('quantity').value || '1', 2);
    const markup = scaled(document.getElementById('markup_percent').value || '0', 4);
    const clientUnit = (base * (1000000n + markup) + 500000n) / 1000000n;
    const baseTotal = (base * quantity + 50n) / 100n;
    const clientTotal = (clientUnit * quantity + 50n) / 100n;
    document.getElementById('client_unit_price').textContent = formatCents(clientUnit);
    document.getElementById('base_line_total').textContent = formatCents(baseTotal);
    document.getElementById('client_line_total').textContent = formatCents(clientTotal);
    document.getElementById('markup_amount').textContent = formatCents(clientTotal - baseTotal);
  }
  ['base_unit_cost', 'quantity', 'markup_percent'].forEach(function(id){ document.getElementById(id).addEventListener('input', updatePreview); });
  updatePreview();
})();
</script>
@endsection
