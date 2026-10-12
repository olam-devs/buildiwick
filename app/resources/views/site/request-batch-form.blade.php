@if($phaseConfiguration !== 1)
<section class="panel empty-state"><h3>Request creation is unavailable</h3><p>{{ $phaseConfiguration === 0 ? 'This project has no active construction phase configured.' : 'This project has multiple active construction phases.' }} Ask the Director to configure exactly one active phase.</p></section>
@else
<form method="POST" enctype="multipart/form-data" action="{{ $isPm ? route('pm.requests.store', $project) : route('site.requests.store', $project) }}" id="requestBatchForm">
@csrf
<section class="panel form-section">
  <h3>Site Request</h3>
  <p class="form-context">List everything needed from Site. The Project Manager will choose suppliers, confirm prices and connect every item to the internal budget.</p>
  <div class="row2">
    <div class="field"><label>Project / Active Phase</label><input value="{{ $project->name }} · Phase {{ $phase->phase_order }} — {{ $phase->stage_label }}" readonly></div>
    <div class="field"><label for="requested_at">Request Date <span class="req">*</span></label><input id="requested_at" type="date" name="requested_at" max="{{ now()->toDateString() }}" value="{{ old('requested_at', now()->toDateString()) }}" required></div>
  </div>
  <div class="field"><label for="evidence">General Supporting Document</label><input id="evidence" type="file" name="evidence" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx"><div class="field-err">Optional photo, list or site document applying to this request.</div></div>
</section>

<section class="panel form-section">
  <div class="topline"><div><h3>Items Needed</h3><p>Use a separate row for each material, service, wage or cash need.</p></div><button type="button" class="btn btn-ghost" id="addRequestItem">+ Add Item</button></div>
  <div id="requestItems"></div>
</section>

<template id="requestItemTemplate">
  <article class="request-item-card" style="border:1px solid #d9e1dc;border-radius:10px;padding:14px;margin-bottom:12px">
    <div class="topline"><strong class="item-number">Item</strong><button type="button" class="btn btn-ghost remove-item">Remove</button></div>
    <div class="row2">
      <div class="field"><label>Type <span class="req">*</span></label><select data-name="request_type" required>@foreach(\App\Models\ExpenseRequest::TYPES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
      <div class="field"><label>Item / Service / Work Description <span class="req">*</span></label><input data-name="item_description" maxlength="1000" placeholder="e.g. Simba cement 42.5R" required></div>
    </div>
    <div class="row3">
      <div class="field"><label>Quantity</label><input data-name="quantity" type="number" min="0.01" step="0.01"></div>
      <div class="field"><label>Unit</label><select data-name="unit"><option value="">No unit / lump sum</option>@foreach($lookups['units'] as $item)<option value="{{ $item->code }}">{{ $item->label }}</option>@endforeach</select></div>
      <div class="field"><label>Estimated Amount (TSh)</label><input data-name="requested_amount" type="number" min="0.01" step="0.01"><div class="field-err">Optional Site estimate; PM enters the final supplier price.</div></div>
    </div>
    <div class="field"><label>Notes / Brand / Specification</label><textarea data-name="notes" rows="2" maxlength="2000" placeholder="Brand, size, colour, delivery instruction or other detail for the PM"></textarea></div>
  </article>
</template>

<div class="panel request-submit-note"><strong>What happens next:</strong> the PM maps every item to an internal-budget line, groups items by supplier, records final prices and documents, then approves the request directly for Accounts.</div>
<div style="display:flex;justify-content:flex-end;gap:8px;margin:16px 0 32px"><a class="btn btn-ghost" href="{{ $isPm ? route('pm.requests.index') : route('site.projects.show', $project) }}">Cancel</a><button class="btn btn-primary" type="submit">Submit Item List to PM</button></div>
</form>

<script>
(function(){
  const container=document.getElementById('requestItems'),template=document.getElementById('requestItemTemplate'),add=document.getElementById('addRequestItem');
  @php($oldRequestItems = old('items', [['request_type' => 'item']]))
  const oldItems=@json($oldRequestItems);
  function renumber(){Array.from(container.children).forEach((card,index)=>{card.querySelector('.item-number').textContent='Item '+(index+1);card.querySelectorAll('[data-name]').forEach(field=>field.name='items['+index+']['+field.dataset.name+']');card.querySelector('.remove-item').disabled=container.children.length===1;});}
  function append(values={}){const card=template.content.firstElementChild.cloneNode(true);card.querySelectorAll('[data-name]').forEach(field=>{if(values[field.dataset.name]!==undefined)field.value=values[field.dataset.name]??'';});card.querySelector('.remove-item').addEventListener('click',()=>{card.remove();renumber();});container.appendChild(card);renumber();}
  oldItems.forEach(append);add.addEventListener('click',()=>append({request_type:'item'}));
})();
</script>
@endif
