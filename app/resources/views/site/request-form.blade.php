@extends('layouts.app')
@php($editing = $expenseRequest !== null)
@php($formAudience = $formAudience ?? 'site_operator')
@php($isPm = $formAudience === 'project_manager')
@section('title', ($editing ? 'Edit Expense Request' : 'New Expense Request').' · Buildiwick')
@section('content')
<a class="back-link" href="{{ $isPm ? route('pm.requests.index') : ($editing ? route('site.requests.show', [$project, $expenseRequest]) : route('site.projects.show', $project)) }}">&larr; Back</a>
<div class="topline"><div><h2>{{ $editing ? 'Edit Expense Request' : 'New Expense Request' }}</h2><p>{{ $project->name }} · {{ $project->project_code }}</p></div></div>
@if($errors->any())<div class="flash error"><ul style="margin:0;padding-left:20px;">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

@if(!$editing)
  @include('site.request-batch-form')
@else
@if($phaseConfiguration !== 1)
  <section class="panel empty-state"><h3>Request creation is unavailable</h3><p>{{ $phaseConfiguration === 0 ? 'This project has no active construction phase configured.' : 'This project has multiple active construction phases, so the current phase is ambiguous.' }} Ask an administrator to configure exactly one active phase.</p></section>
@else
<form method="POST" enctype="multipart/form-data" action="{{ $isPm ? route('pm.requests.store', $project) : ($editing ? route('site.requests.update', [$project, $expenseRequest]) : route('site.requests.store', $project)) }}" id="siteRequestForm">
  @csrf @if($editing) @method('PUT') @endif
  <section class="panel form-section"><h3>Request Details</h3>
    <div class="row2">
      <div class="field"><label>Project</label><input value="{{ $project->name }} · {{ $project->project_code }}" readonly></div>
      <div class="field"><label>Active Phase</label><input value="Phase {{ $phase->phase_order }} — {{ $phase->stage_label }}" readonly><div class="field-err">Assigned automatically from the project’s single active phase.</div></div>
    </div>
    <div class="row2"><div class="field"><label for="requested_at">Request Date <span class="req">*</span></label><input id="requested_at" type="date" name="requested_at" max="{{ now()->toDateString() }}" value="{{ old('requested_at', $expenseRequest?->requested_at?->format('Y-m-d') ?? now()->toDateString()) }}" required></div><div class="field"><label for="request_type">Request Type <span class="req">*</span></label><select id="request_type" name="request_type" required>@foreach(\App\Models\ExpenseRequest::TYPES as $value => $label)<option value="{{ $value }}" @selected(old('request_type', $expenseRequest?->request_type ?? request('type', 'item')) === $value)>{{ $label }}</option>@endforeach</select></div></div>
    <div class="row2">
      <div class="field"><label for="main_category">Main Category <span class="req">*</span></label><select name="main_category" id="main_category" required><option value="">Select category</option>@foreach($lookups['categories'] as $category)<option value="{{ $category->code }}" @selected(old('main_category', $expenseRequest?->main_category ?? request('category')) === $category->code)>{{ $category->label }}</option>@endforeach</select><div class="field-err" id="categoryGuidance"></div></div>
      <div class="field"><label for="subcategory">Subcategory</label><select name="subcategory" id="subcategory"><option value="">No subcategory / Other</option>@foreach($lookups['subcategories'] as $item)<option value="{{ $item->code }}" data-parent="{{ $item->parent_code }}" @selected(old('subcategory', $expenseRequest?->subcategory) === $item->code)>{{ $item->label }}</option>@endforeach</select><div class="field-err">Optional where no matching lookup exists.</div></div>
    </div>
    <div class="field" id="labourAgreementField"><label for="labour_agreement_id">Labour / Subcontract Agreement</label><select id="labour_agreement_id" name="labour_agreement_id"><option value="">No agreement / request first</option>@foreach(($labourAgreements ?? collect()) as $agreement)<option value="{{ $agreement->id }}" @selected((int)old('labour_agreement_id', $expenseRequest?->labour_agreement_id ?? request('agreement_id')) === $agreement->id)>{{ $agreement->agreement_code }} · {{ $agreement->fundi_subcontractor }} · {{ $agreement->scope_of_work }}</option>@endforeach</select><div class="field-err">Optional. The PM creates agreements; Site can raise wages or subcontract requests against them.</div></div>
    <div class="field"><label for="item_description">Item / Description <span class="req">*</span></label><input id="item_description" name="item_description" maxlength="1000" value="{{ old('item_description', $expenseRequest?->item_description) }}" required></div>
  </section>

  <section class="panel form-section"><h3>Quantity and Details</h3>
    <div class="row2">
      <div class="field"><label for="quantity">Quantity</label><input id="quantity" name="quantity" type="number" min="0" step="0.01" value="{{ old('quantity', $expenseRequest?->quantity) }}"><div class="field-err">Leave blank when the need is a lump-sum item; the Project Manager prices it later.</div></div>
      <div class="field"><label for="unit">Unit</label><select id="unit" name="unit"><option value="">No unit</option>@foreach($lookups['units'] as $item)<option value="{{ $item->code }}" @selected(old('unit', $expenseRequest?->unit ?? 'ls') === $item->code)>{{ $item->label }}</option>@endforeach</select><div class="field-err">Suggested units move to the top by category; all valid units remain available.</div></div>
    </div>
    <div class="field"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="3" maxlength="2000">{{ old('notes', $expenseRequest?->notes) }}</textarea></div>
    <div class="field"><label for="evidence">Supporting Evidence</label><input id="evidence" type="file" name="evidence" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx"><div class="field-err">PDF, image, Word or Excel document · maximum 20 MB · stored privately.</div>@if($editing && $expenseRequest->evidence_link)<div class="field-err">Existing evidence is retained unless replaced.</div>@endif</div>
  </section>

  <div class="panel request-submit-note"><strong>{{ $isPm ? 'PM-created request:' : 'Need report:' }}</strong> {{ $isPm ? 'After creation, add supplier, pricing, payment instructions, budget allocation and any evidence before approving it for Accounts.' : 'Submit the requirement and context. The Project Manager handles supplier selection, budget allocation and pricing before approval for Accounts.' }}</div>
  <div style="display:flex;justify-content:flex-end;gap:8px;margin:16px 0 32px;"><a class="btn btn-ghost" href="{{ $isPm ? route('pm.requests.index') : ($editing ? route('site.requests.show', [$project, $expenseRequest]) : route('site.projects.show', $project)) }}">Cancel</a><button class="btn btn-primary" type="submit">{{ $editing ? 'Save Pending Request' : ($isPm ? 'Create and Add Commercial Details' : 'Submit Expense Request') }}</button></div>
</form>
<script>
(function(){
  const category=document.getElementById('main_category');
  const subcategory=document.getElementById('subcategory');
  const unit=document.getElementById('unit');
  const requestType=document.getElementById('request_type');
  const unitRecommendations=@json($lookups['unitRecommendations']);
  const categoryGuidance=@json(\App\Models\ExpenseRequest::CATEGORY_GUIDANCE);
  const agreementField=document.getElementById('labourAgreementField');
  const agreement=document.getElementById('labour_agreement_id');
  const guidance=document.getElementById('categoryGuidance');
  function filterSubs(){Array.from(subcategory.options).forEach(function(option){if(!option.value)return;option.hidden=option.dataset.parent!==category.value;if(option.hidden&&option.selected)subcategory.value='';});agreementField.hidden=category.value!=='labour_subcontracts';if(agreementField.hidden)agreement.value='';guidance.textContent=categoryGuidance[category.value]||'';}
  category.addEventListener('change',filterSubs);filterSubs();
  function guideUnits(){const preferred=unitRecommendations[category.value]||[];Array.from(unit.options).filter(option=>option.value).sort((left,right)=>(preferred.indexOf(left.value)<0?1000:preferred.indexOf(left.value))-(preferred.indexOf(right.value)<0?1000:preferred.indexOf(right.value))).forEach(option=>unit.appendChild(option));}
  category.addEventListener('change',guideUnits);guideUnits();
  function guideAmount(){if(requestType.value==='salary_wage'&&category.value!=='labour_subcontracts'){category.value='labour_subcontracts';filterSubs();guideUnits();}}
  requestType.addEventListener('change',guideAmount);guideAmount();
})();
</script>
@endif
@endif
@endsection
