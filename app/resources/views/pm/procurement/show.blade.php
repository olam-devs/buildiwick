@extends('layouts.app')
@section('title', 'Procurement '.$anchor->request_batch_code.' · Buildiwick')
@section('content')
<a class="back-link" href="{{ route('pm.requests.index') }}">&larr; Back to Requests</a>
<div class="topline"><div><h2>Request {{ $anchor->request_batch_code ?: $anchor->request_code }}</h2><p>{{ $project->name }} · Site-to-supplier workflow</p></div></div>
@if($errors->any())<div class="flash error"><ul style="margin:0;padding-left:20px">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<nav class="panel" style="display:flex;gap:8px;flex-wrap:wrap">
  <a class="btn {{ $step === 1 ? 'btn-primary' : 'btn-ghost' }}" href="{{ route('pm.procurement.show', [$anchor, 'step'=>1]) }}">1 · Verify Site Items ({{ $items->where('procurement_stage','site_review')->count() }})</a>
  <a class="btn {{ $step === 2 ? 'btn-primary' : 'btn-ghost' }}" href="{{ route('pm.procurement.show', [$anchor, 'step'=>2]) }}">2 · Assign Suppliers ({{ $items->where('procurement_stage','supplier_assignment')->count() }})</a>
  <a class="btn {{ $step === 3 ? 'btn-primary' : 'btn-ghost' }}" href="{{ route('pm.procurement.show', [$anchor, 'step'=>3]) }}">3 · Price & Approve ({{ $groups->count() }})</a>
</nav>

@if($step === 1)
<form method="POST" action="{{ route('pm.procurement.move', $anchor) }}">@csrf @method('PUT')<input type="hidden" name="direction" value="to_supplier_assignment">
<section class="panel"><div class="topline"><div><h3>Step 1 · Verify the Site Operator’s Context</h3><p>Edit any incorrect detail, map new items to an exact approved budget line, then move selected rows forward.</p></div><button class="btn btn-primary" type="submit">Move Selected to Step 2</button></div>
@php($stageItems=$items->where('procurement_stage','site_review'))
@forelse($stageItems as $item)
<article style="border:2px solid {{ $item->is_new_item ? '#e6a15b' : '#d9e1dc' }};border-radius:10px;padding:14px;margin:12px 0">
 <div class="topline"><label><input type="checkbox" name="item_ids[]" value="{{ $item->id }}"> <strong>{{ $item->item_description }}</strong></label><span>{{ $item->is_new_item ? 'NEW ITEM — needs budget mapping' : 'Matched by Site' }}</span></div>
 <div class="row2"><div class="field"><label>Description</label><input name="items[{{ $item->id }}][item_description]" value="{{ $item->item_description }}" required></div><div class="field"><label>Approved Budget Line</label><select name="items[{{ $item->id }}][internal_budget_entry_id]" required><option value="">Select exact budget line</option>@foreach($budgetEntries as $entry)<option value="{{ $entry->id }}" @selected($item->internal_budget_entry_id===$entry->id)>{{ $entry->phase?->stage_label }} · {{ $entry->work_area }} · {{ $entry->construction_category }} · {{ $entry->work_package_code }} {{ $entry->work_package_name }} · {{ $entry->item_description }}</option>@endforeach</select></div></div>
 <div class="row2"><div class="field"><label>Phase</label><select name="items[{{ $item->id }}][project_phase_id]" required>@foreach($phases as $phase)<option value="{{ $phase->id }}" @selected($item->project_phase_id===$phase->id)>{{ $phase->stage_label }}</option>@endforeach</select></div><div class="field"><label>Work Area</label><input name="items[{{ $item->id }}][work_area]" value="{{ $item->work_area }}" required></div></div>
 <div class="row2"><div class="field"><label>Construction Category</label><input name="items[{{ $item->id }}][construction_category]" value="{{ $item->construction_category }}" required></div><div class="field"><label>Work Package</label><div style="display:grid;grid-template-columns:90px 1fr;gap:8px"><input name="items[{{ $item->id }}][work_package_code]" value="{{ $item->work_package_code }}" placeholder="Code"><input name="items[{{ $item->id }}][work_package_name]" value="{{ $item->work_package_name }}" required></div></div></div>
 <div class="row2"><div class="field"><label>Quantity</label><input name="items[{{ $item->id }}][quantity]" type="number" min="0.01" step="0.01" value="{{ $item->quantity }}"></div><div class="field"><label>Unit</label><input name="items[{{ $item->id }}][unit]" value="{{ $item->unit }}"></div></div>
</article>
@empty <div class="empty-state">No items are waiting in Step 1.</div> @endforelse
</section></form>
@elseif($step === 2)
<section class="panel"><h3>Step 2 · Select Items and Assign a Supplier</h3><p>Items stay separate and are sorted A–Z. Select any combination to create one supplier group. Pricing happens only in Step 3.</p>
<form method="POST" action="{{ route('pm.procurement.groups.store', $anchor) }}">@csrf
 <div style="overflow-x:auto"><table><thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.step2-item').forEach(box=>box.checked=this.checked)"></th><th>Item</th><th>Qty</th><th>Origin / Budget</th></tr></thead><tbody>
 @forelse($items->where('procurement_stage','supplier_assignment')->sortBy('item_description') as $item)<tr style="cursor:pointer" onclick="if(event.target.tagName!=='INPUT')this.querySelector('input').click()"><td><input class="step2-item" type="checkbox" name="item_ids[]" value="{{ $item->id }}"></td><td><strong>{{ $item->item_description }}</strong><br><small>{{ $item->notes }}</small></td><td>{{ $item->quantity }} {{ $item->unit }}</td><td>{{ $item->phase?->stage_label }} · {{ $item->work_area }} · {{ $item->construction_category }} · {{ $item->work_package_name }}</td></tr>@empty<tr><td colspan="4">No ungrouped items remain in Step 2.</td></tr>@endforelse
 </tbody></table></div>
 <div class="row2" style="margin-top:14px"><div class="field"><label>Existing Supplier</label><select name="supplier_payee_id"><option value="">Create a new supplier below</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach</select></div><div class="field"><label>New Supplier Name</label><input name="new_supplier_name" placeholder="Leave blank when selecting an existing supplier"></div></div>
 <div class="row2"><div class="field"><label>New Supplier Phone</label><input name="new_supplier_phone"></div><div class="field"><label>New Supplier Email</label><input type="email" name="new_supplier_email"></div></div>
 <div class="field"><label>Grouping Note</label><textarea name="notes" rows="2"></textarea></div>
 <div style="display:flex;justify-content:flex-end"><button class="btn btn-primary">Create Supplier Group and Move to Step 3</button></div>
</form></section>
@else
<section class="panel"><h3>Step 3 · Price, Inspect and Approve Supplier Groups</h3><p>Each supplier is independent. Save supplier payment details and all item prices before approving that group for Accounts.</p>
@forelse($groups as $group)
<article style="border:1px solid #d9e1dc;border-radius:10px;padding:16px;margin:14px 0">
 <div class="topline"><div><h3 style="margin:0">{{ $group->supplier->name }}</h3><p>{{ $group->group_code }} · {{ strtoupper($group->status) }} · TSh {{ number_format((float)$group->total_amount,2) }}</p></div></div>
 @if($group->status==='draft')
 <form method="POST" enctype="multipart/form-data" action="{{ route('pm.procurement.groups.price', $group) }}">@csrf @method('PUT')
 <div style="overflow-x:auto"><table><thead><tr><th>Item</th><th>Qty</th><th>Budget Allocation</th><th>Unit Price (TSh)</th><th>Total</th></tr></thead><tbody>@foreach($group->items as $item)<tr><td>{{ $item->item_description }}</td><td>{{ $item->quantity ?? 1 }} {{ $item->unit }}</td><td>{{ $item->internalBudgetEntry?->item_description }}</td><td><input style="min-width:140px" type="number" min="0.01" step="0.01" name="items[{{ $item->id }}][commercial_unit_rate]" value="{{ $item->commercial_unit_rate }}" required></td><td>{{ $item->commercial_total ? number_format((float)$item->commercial_total,2) : 'Calculated on save' }}</td></tr>@endforeach</tbody></table></div>
 <div class="row2" style="margin-top:14px"><div class="field"><label>Payment Method</label><select name="payment_method" required><option value="">Select method</option>@foreach($paymentMethods as $method)<option value="{{ $method->code }}" @selected($group->payment_method===$method->code)>{{ $method->label }}</option>@endforeach</select></div><div class="field"><label>Supplier Payment Destination</label><select name="payment_destination_id"><option value="">None / cash / add in Supplier Directory</option>@foreach($group->supplier->paymentDestinations->where('is_active',true) as $destination)<option value="{{ $destination->id }}" @selected($group->payment_destination_id===$destination->id)>{{ $destination->payment_method }} · {{ $destination->account_wallet_name }} · {{ $destination->account_wallet_number }}</option>@endforeach</select></div></div>
 <details style="margin-bottom:14px"><summary>Add new supplier payment details</summary><div class="row2" style="margin-top:12px"><div class="field"><label>Account / Wallet Name</label><input name="new_account_wallet_name" value="{{ $group->supplier->name }}"></div><div class="field"><label>Account / Wallet Number</label><input name="new_account_wallet_number"></div></div><div class="row2"><div class="field"><label>Bank / Mobile Provider</label><input name="new_bank_provider_name"></div><div class="field"><label>Branch</label><input name="new_branch"></div></div></details>
 <div class="row2"><div class="field"><label>Quotation / Supporting Document</label><input type="file" name="evidence"></div><div class="field"><label>Commercial Notes</label><textarea name="notes" rows="2">{{ $group->notes }}</textarea></div></div>
 <div style="display:flex;justify-content:flex-end"><button class="btn btn-primary">Save Group Pricing</button></div></form>
 <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px">
  <form method="POST" action="{{ route('pm.procurement.groups.decision',$group) }}">@csrf<input type="hidden" name="decision" value="returned"><input type="hidden" name="notes" value="Returned by PM for regrouping"><button class="btn btn-ghost">Return Items to Step 2</button></form>
  <form method="POST" action="{{ route('pm.procurement.groups.decision',$group) }}">@csrf<input type="hidden" name="decision" value="approved"><input type="text" name="notes" placeholder="Approval / variance note"><button class="btn btn-primary">Approve Group for Accounts</button></form>
 </div>
 @else <div class="flash success">Approved for Accounts on {{ $group->approved_at?->format('d M Y H:i') }}.</div> @endif
</article>
@empty <div class="empty-state">No supplier groups yet. Select items in Step 2 first.</div> @endforelse
</section>
@endif
@endsection
