@if($phaseConfiguration !== 1)
<section class="panel empty-state"><h3>Request creation is unavailable</h3><p>{{ $phaseConfiguration === 0 ? 'This project has no active construction phase configured.' : 'This project has multiple active construction phases.' }} Ask the Director to confirm one current phase first.</p></section>
@else
<form method="POST" enctype="multipart/form-data" action="{{ $isPm ? route('pm.requests.store', $project) : route('site.requests.store', $project) }}" id="requestBatchForm">
@csrf
<section class="panel form-section">
  <h3>Site Request</h3>
  <p class="form-context">Describe what is needed and where it will be used. Do not enter prices—the Project Manager handles suppliers and pricing.</p>
  <div class="row2"><div class="field"><label>Project</label><input value="{{ $project->name }} · {{ $project->project_code }}" readonly></div><div class="field"><label for="requested_at">Request Date <span class="req">*</span></label><input id="requested_at" type="date" name="requested_at" max="{{ now()->toDateString() }}" value="{{ old('requested_at', now()->toDateString()) }}" required></div></div>
  <div class="field"><label for="evidence">General Supporting Document</label><input id="evidence" type="file" name="evidence" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx"><div class="field-err">Optional photo, list or site document applying to this request.</div></div>
</section>
<section class="panel form-section"><div class="topline"><div><h3>Items Needed</h3><p>Classify each item by its intended construction use. Existing budget materials are suggested automatically.</p></div><button type="button" class="btn btn-ghost" id="addRequestItem">+ Add Item</button></div><div id="requestItems"></div></section>
<template id="requestItemTemplate">
  <article class="request-item-card" style="border:2px solid #d9e1dc;border-radius:10px;padding:14px;margin-bottom:12px">
    <div class="topline"><strong class="item-number">Item</strong><button type="button" class="btn btn-ghost remove-item">Remove</button></div>
    <div class="row2"><div class="field"><label>Type <span class="req">*</span></label><select data-name="request_type" required>@foreach(\App\Models\ExpenseRequest::TYPES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div><div class="field"><label>Project Phase <span class="req">*</span></label><select data-name="project_phase_id" required><option value="">Select phase</option>@foreach($phases as $projectPhase)<option value="{{ $projectPhase->id }}">{{ $projectPhase->phase_order }}. {{ $projectPhase->stage_label }}</option>@endforeach</select></div></div>
    <div class="row2"><div class="field"><label>Work Area / Building <span class="req">*</span></label><input data-name="work_area" maxlength="255" placeholder="e.g. Main house, boundary wall" required></div><div class="field"><label>Construction Category <span class="req">*</span></label><input data-name="construction_category" maxlength="255" placeholder="e.g. Substructure, plastering" required></div></div>
    <div class="row2"><div class="field"><label>Work Package Code</label><input data-name="work_package_code" maxlength="50" placeholder="e.g. A"></div><div class="field"><label>Work Package <span class="req">*</span></label><input data-name="work_package_name" maxlength="255" placeholder="e.g. Cement and binders" required></div></div>
    <div class="field"><label>Material / Item / Service <span class="req">*</span></label><input data-name="item_description" class="item-description" list="budgetMaterialOptions" maxlength="1000" placeholder="Start typing to select a budget item, or add a new item" required><input type="hidden" data-name="internal_budget_entry_id" class="budget-entry-id"><div class="field-err match-note">New descriptions are highlighted for PM verification.</div></div>
    <div class="row2"><div class="field"><label>Quantity</label><input data-name="quantity" type="number" min="0.01" step="0.01"></div><div class="field"><label>Unit</label><select data-name="unit"><option value="">No unit / lump sum</option>@foreach($lookups['units'] as $item)<option value="{{ $item->code }}">{{ $item->label }}</option>@endforeach</select></div></div>
    <div class="field"><label>Notes / Brand / Specification</label><textarea data-name="notes" rows="2" maxlength="2000" placeholder="Brand, size, colour, delivery instruction or other detail for the PM"></textarea></div>
  </article>
</template>
<datalist id="budgetMaterialOptions">@foreach($budgetEntries as $entry)<option value="{{ $entry->item_description }}">{{ $entry->phase?->stage_label }} · {{ $entry->work_area }} · {{ $entry->construction_category }} · {{ $entry->work_package_name }}</option>@endforeach</datalist>
<div class="panel request-submit-note"><strong>Next:</strong> the PM verifies these rows, assigns selected items to supplier groups, then prices and approves each supplier group independently for Accounts.</div>
<div style="display:flex;justify-content:flex-end;gap:8px;margin:16px 0 32px"><a class="btn btn-ghost" href="{{ $isPm ? route('pm.requests.index') : route('site.projects.show', $project) }}">Cancel</a><button class="btn btn-primary" type="submit">Submit Item List to PM</button></div>
</form>
<script>
(function(){
 const container=document.getElementById('requestItems'),template=document.getElementById('requestItemTemplate'),add=document.getElementById('addRequestItem');
 @php
   $budgetEntryOptions = $budgetEntries->map(function ($entry) {
       return [
           'id' => $entry->id,
           'description' => $entry->item_description,
           'phase_id' => $entry->project_phase_id,
           'work_area' => $entry->work_area,
           'construction_category' => $entry->construction_category,
           'work_package_code' => $entry->work_package_code,
           'work_package_name' => $entry->work_package_name,
           'unit' => $entry->unit,
       ];
   })->values();
   $oldRequestItems = old('items', [['request_type' => 'item', 'project_phase_id' => $phase?->id]]);
 @endphp
 const entries=@json($budgetEntryOptions);
 const oldItems=@json($oldRequestItems);
 function renumber(){Array.from(container.children).forEach((card,index)=>{card.querySelector('.item-number').textContent='Item '+(index+1);card.querySelectorAll('[data-name]').forEach(field=>field.name='items['+index+']['+field.dataset.name+']');card.querySelector('.remove-item').disabled=container.children.length===1;});}
 function detect(card){const input=card.querySelector('.item-description'),match=entries.find(entry=>entry.description.toLowerCase()===input.value.trim().toLowerCase());card.querySelector('.budget-entry-id').value=match?.id||'';card.style.borderColor=match?'#62a879':'#e6a15b';card.querySelector('.match-note').textContent=match?'Matched to approved internal budget.':'New item — PM will see this highlighted.';if(match){card.querySelector('[data-name="project_phase_id"]').value=match.phase_id;['work_area','construction_category','work_package_code','work_package_name','unit'].forEach(key=>{if(match[key])card.querySelector('[data-name="'+key+'"]').value=match[key];});}}
 function append(values={}){const card=template.content.firstElementChild.cloneNode(true);card.querySelectorAll('[data-name]').forEach(field=>{if(values[field.dataset.name]!==undefined)field.value=values[field.dataset.name]??'';});card.querySelector('.item-description').addEventListener('input',()=>detect(card));card.querySelector('.remove-item').addEventListener('click',()=>{card.remove();renumber();});container.appendChild(card);detect(card);renumber();}
 oldItems.forEach(append);add.addEventListener('click',()=>append({request_type:'item',project_phase_id:'{{ $phase?->id }}'}));
})();
</script>
@endif
