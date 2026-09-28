@php
  $categoryLabels = [
    'materials' => 'Materials',
    'labour_subcontracts' => 'Labour & Subcontracts',
    'logistics_equipment' => 'Logistics & Equipment',
    'preliminaries_overheads' => 'Preliminaries & Overheads',
  ];
  $lookupsJson = ($drawerLookups ?? collect())->map(fn ($group) => $group->map(fn ($l) => ['code' => $l->code, 'label' => $l->label, 'parent_code' => $l->parent_code]))->toJson();
  $agreementsJson = ($drawerAgreements ?? collect())->map(fn ($a) => ['id' => $a->id, 'project_id' => $a->project_id, 'label' => $a->fundi_subcontractor.' · '.$a->scope_of_work])->toJson();
@endphp

<div class="drawer-overlay" id="drawerOverlay" onclick="Buildiwick.closeDrawer()"></div>
<div class="drawer" id="drawer" role="dialog" aria-label="New entry">
  <div class="drawer-head">
    <h3>New Entry</h3>
    <button type="button" class="drawer-close" onclick="Buildiwick.closeDrawer()" aria-label="Close">&times;</button>
  </div>
  <div class="drawer-body">

    @if ($errors->any())
      <div class="flash error">{{ $errors->first() }}</div>
    @endif

    <div class="cattabs" id="cattabs">
      @foreach ($categoryLabels as $code => $label)
        <div class="cattab {{ $loop->first ? 'active' : '' }}" data-cat="{{ $code }}" onclick="Buildiwick.selectCategory('{{ $code }}')">{{ $label }}</div>
      @endforeach
    </div>

    <div class="modetabs" id="modetabs" hidden>
      <button type="button" class="modetab active" data-mode="record_payment" onclick="Buildiwick.selectMode('record_payment')">Record payment</button>
      <button type="button" class="modetab" data-mode="new_agreement" onclick="Buildiwick.selectMode('new_agreement')">New agreement</button>
    </div>

    {{-- Materials / Logistics / Preliminaries / Labour-payment all post here --}}
    <form id="txnForm" method="POST" action="{{ url('/entries/transactions') }}" enctype="multipart/form-data">
      @csrf
      <input type="hidden" name="main_category" id="txn_main_category" value="materials">

      <div class="field">
        <label for="txn_project_id">Project <span class="req">*</span></label>
        <select name="project_id" id="txn_project_id" onchange="Buildiwick.onProjectChange()">
          @foreach (($drawerProjects ?? []) as $p)
            <option value="{{ $p->id }}" @selected(old('project_id') == $p->id)>{{ $p->name }}</option>
          @endforeach
        </select>
      </div>

      <div class="field" id="txn_agreement_field" hidden>
        <label for="txn_agreement_id">Agreement <span class="req">*</span></label>
        <select name="agreement_id" id="txn_agreement_id"></select>
      </div>

      <div class="field">
        <label for="txn_description">Description <span class="req">*</span></label>
        <input type="text" name="item_description" id="txn_description" value="{{ old('item_description') }}" placeholder="e.g. Cement, 50 bags">
      </div>

      <div class="field" id="txn_subcategory_field">
        <label for="txn_subcategory">Type</label>
        <select name="subcategory" id="txn_subcategory"><option value="">&mdash; none &mdash;</option></select>
      </div>

      <div class="field">
        <label for="txn_supplier">Supplier / fundi / client</label>
        <input type="text" name="supplier_fundi_client" id="txn_supplier" value="{{ old('supplier_fundi_client') }}">
      </div>

      <div class="row3" id="txn_qty_row">
        <div class="field"><label for="txn_qty">Quantity</label><input type="number" step="0.01" name="quantity" id="txn_qty" oninput="Buildiwick.calcTotal()"></div>
        <div class="field"><label for="txn_unit">Unit</label><select name="unit" id="txn_unit"><option value="">&mdash;</option></select></div>
        <div class="field"><label for="txn_unit_cost">Unit cost</label><input type="number" step="0.01" name="unit_cost" id="txn_unit_cost" oninput="Buildiwick.calcTotal()"></div>
      </div>

      <div class="row2">
        <div class="field"><label for="txn_total">Total (TSh) <span class="req">*</span></label><input type="number" step="0.01" name="total_amount" id="txn_total" value="{{ old('total_amount') }}"></div>
        <div class="field"><label for="txn_date">Date <span class="req">*</span></label><input type="date" name="transaction_date" id="txn_date" value="{{ old('transaction_date', date('Y-m-d')) }}"></div>
      </div>

      <div class="field">
        <label for="txn_evidence">Receipt photo</label>
        <input type="file" name="evidence" id="txn_evidence" accept="image/*">
      </div>

      <div class="field">
        <label for="txn_notes">Notes</label>
        <textarea name="notes" id="txn_notes" rows="2">{{ old('notes') }}</textarea>
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">Save entry</button>
    </form>

    {{-- Labour tab, "New agreement" mode --}}
    <form id="agreementForm" method="POST" action="{{ url('/entries/labour-agreements') }}" hidden>
      @csrf
      <div class="field">
        <label for="agr_project_id">Project <span class="req">*</span></label>
        <select name="project_id" id="agr_project_id">
          @foreach (($drawerProjects ?? []) as $p)
            <option value="{{ $p->id }}">{{ $p->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label for="agr_fundi">Worker / fundi / subcontractor <span class="req">*</span></label>
        <input type="text" name="fundi_subcontractor" id="agr_fundi" placeholder="e.g. Juma Mwakisu">
      </div>
      <div class="field">
        <label for="agr_scope">Scope of work</label>
        <input type="text" name="scope_of_work" id="agr_scope" placeholder="e.g. Roofing labour">
      </div>
      <div class="row2">
        <div class="field">
          <label for="agr_type">Labour type</label>
          <select name="labour_type" id="agr_type"><option value="">&mdash; none &mdash;</option></select>
        </div>
        <div class="field"><label for="agr_value">Agreed value (TSh) <span class="req">*</span></label><input type="number" step="0.01" name="agreed_value" id="agr_value"></div>
      </div>
      <div class="row2">
        <div class="field"><label for="agr_date">Agreement date <span class="req">*</span></label><input type="date" name="agreement_date" id="agr_date" value="{{ date('Y-m-d') }}"></div>
        <div class="field"><label for="agr_contact">Contact</label><input type="text" name="contact" id="agr_contact"></div>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">Save agreement</button>
    </form>

  </div>
</div>

<script>
window.Buildiwick = (function(){
  var lookups = {!! $lookupsJson !!};
  var agreements = {!! $agreementsJson !!};
  var state = { category: 'materials', mode: 'record_payment' };

  function fillSelect(select, items, placeholder){
    select.innerHTML = '<option value="">' + placeholder + '</option>';
    items.forEach(function(item){
      var opt = document.createElement('option');
      opt.value = item.value; opt.textContent = item.label;
      select.appendChild(opt);
    });
  }

  function refreshSubcategory(){
    var sub = (lookups.subcategory || []).filter(function(l){ return l.parent_code === state.category; });
    fillSelect(document.getElementById('txn_subcategory'), sub.map(function(l){ return {value:l.code, label:l.label}; }), '— none —');
    document.getElementById('txn_subcategory_field').hidden = sub.length === 0;
  }

  function refreshUnits(){
    var units = (lookups.unit || []).map(function(l){ return {value:l.code, label:l.label}; });
    fillSelect(document.getElementById('txn_unit'), units, '—');
  }

  function refreshLabourType(){
    var types = (lookups.subcategory || []).filter(function(l){ return l.parent_code === 'labour_subcontracts'; });
    fillSelect(document.getElementById('agr_type'), types.map(function(l){ return {value:l.code, label:l.label}; }), '— none —');
  }

  function refreshAgreements(){
    var projectId = parseInt(document.getElementById('txn_project_id').value, 10);
    var list = agreements.filter(function(a){ return a.project_id === projectId; });
    fillSelect(document.getElementById('txn_agreement_id'), list.map(function(a){ return {value:a.id, label:a.label}; }), list.length ? 'Select agreement' : 'No agreements on this project yet');
  }

  function applyVisibility(){
    var isLabour = state.category === 'labour_subcontracts';
    document.getElementById('modetabs').hidden = !isLabour;
    var showAgreementForm = isLabour && state.mode === 'new_agreement';
    document.getElementById('agreementForm').hidden = !showAgreementForm;
    document.getElementById('txnForm').hidden = showAgreementForm;
    document.getElementById('txn_agreement_field').hidden = !(isLabour && state.mode === 'record_payment');
    document.getElementById('txn_qty_row').hidden = isLabour;
    document.getElementById('txn_main_category').value = state.category;
    if (isLabour && state.mode === 'record_payment') refreshAgreements();
  }

  return {
    openDrawer: function(){ document.getElementById('drawerOverlay').classList.add('open'); document.getElementById('drawer').classList.add('open'); },
    closeDrawer: function(){ document.getElementById('drawerOverlay').classList.remove('open'); document.getElementById('drawer').classList.remove('open'); },
    selectCategory: function(cat){
      state.category = cat;
      document.querySelectorAll('#cattabs .cattab').forEach(function(el){ el.classList.toggle('active', el.dataset.cat === cat); });
      refreshSubcategory();
      applyVisibility();
    },
    selectMode: function(mode){
      state.mode = mode;
      document.querySelectorAll('#modetabs .modetab').forEach(function(el){ el.classList.toggle('active', el.dataset.mode === mode); });
      applyVisibility();
    },
    onProjectChange: function(){ if (state.category === 'labour_subcontracts' && state.mode === 'record_payment') refreshAgreements(); },
    calcTotal: function(){
      var qty = parseFloat(document.getElementById('txn_qty').value);
      var cost = parseFloat(document.getElementById('txn_unit_cost').value);
      if (!isNaN(qty) && !isNaN(cost)) document.getElementById('txn_total').value = (qty * cost).toFixed(2);
    },
    init: function(){ refreshUnits(); refreshLabourType(); refreshSubcategory(); applyVisibility(); }
  };
})();
document.addEventListener('DOMContentLoaded', Buildiwick.init);
@if ($errors->any()) document.addEventListener('DOMContentLoaded', Buildiwick.openDrawer); @endif
</script>
