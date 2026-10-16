@extends('layouts.app')
@section('title', 'Create Client Invoice · '.$project->name)
@section('content')
<a class="back-link" href="{{ route('accountant.projects.show', $project) }}">&larr; Back to {{ $project->name }}</a>
<div class="topline"><div><h2>{{ $estimate ? 'Create Invoice from Estimate' : 'Create Manual Client Invoice' }}</h2><p>{{ $project->name }} · {{ $project->project_code }} · {{ $project->client?->name ?? 'No client linked' }}</p></div></div>
@if($errors->any())<div class="flash error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<form method="POST" action="{{ route('accountant.invoices.store', $project) }}"><input type="hidden" name="project_id" value="{{ $project->id }}"><input type="hidden" name="client_id" value="{{ $project->client_id }}">@csrf
<section class="panel form-section"><h3>Invoice Context</h3><div class="row2"><div class="field"><label>Project</label><input value="{{ $project->name }} · {{ $project->project_code }}" readonly></div><div class="field"><label>Client</label><input value="{{ $project->client?->name ?? 'No client linked' }}" readonly></div></div><div class="row2"><div class="field"><label for="due_at">Due Date <span class="req">*</span></label><input id="due_at" name="due_at" type="date" value="{{ old('due_at', now()->addDays(30)->toDateString()) }}" required></div><div class="field"><label for="reference">Reference / Subject</label><input id="reference" name="reference" value="{{ old('reference') }}"></div></div><div class="field"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="2">{{ old('notes') }}</textarea></div></section>

@if($estimate)
<input type="hidden" name="project_estimate_id" value="{{ $estimate->id }}">
<section class="panel form-section"><div class="topline"><div><h3>Select Estimate Items to Invoice</h3><p class="form-context">Tick one or more phases or individual estimate lines. Edit “This Invoice” to the exact amount being invoiced now; it cannot exceed the remaining estimate amount.</p></div><button type="button" class="btn btn-ghost" id="selectAllRemaining">Select All Remaining</button></div>
@foreach($estimate->entries->groupBy(fn($entry) => $entry->phase?->stage_label ?? 'Other') as $phaseLabel => $phaseEntries)
<article style="border:1px solid var(--line);border-radius:10px;margin:12px 0;overflow:hidden">
 <div style="padding:12px 14px;background:var(--surface-2);display:flex;justify-content:space-between"><label><input type="checkbox" class="phase-toggle"> <strong>{{ $phaseLabel }}</strong></label><span>Remaining TSh {{ \App\Support\Money::format(\App\Support\Money::sum($phaseEntries->pluck('remaining_amount'))) }}</span></div>
 <div style="overflow-x:auto"><table><thead><tr><th>Select</th><th>Ref / Work Section</th><th>Description</th><th>Qty</th><th>Contract</th><th>Previously Claimed</th><th>Remaining</th><th>This Invoice</th></tr></thead><tbody>
 @foreach($phaseEntries as $entry)<tr style="{{ \App\Support\Money::compare($entry->remaining_amount,'0') <= 0 ? 'opacity:.55' : '' }}">
  <td><input type="hidden" name="items[{{ $entry->id }}][estimate_entry_id]" value="{{ $entry->id }}"><input class="boq-select" type="checkbox" name="items[{{ $entry->id }}][selected]" value="1" @disabled(\App\Support\Money::compare($entry->remaining_amount,'0') <= 0)></td>
  <td>{{ $entry->item_reference ?: '—' }}<br><small>{{ $entry->work_area }} {{ $entry->work_section }}</small></td><td>{{ $entry->item_description }}</td><td>{{ $entry->quantity }} {{ $entry->unit }}</td><td>TSh {{ \App\Support\Money::format($entry->line_total) }}</td><td>TSh {{ \App\Support\Money::format($entry->claimed_amount) }}</td><td>TSh {{ \App\Support\Money::format($entry->remaining_amount) }}</td>
  <td><input class="claim-input" style="min-width:150px" type="number" min="0.01" max="{{ $entry->remaining_amount }}" step="0.01" name="items[{{ $entry->id }}][claim_amount]" value="{{ old('items.'.$entry->id.'.claim_amount', $entry->remaining_amount) }}" @disabled(\App\Support\Money::compare($entry->remaining_amount,'0') <= 0)></td>
  <input type="hidden" name="items[{{ $entry->id }}][project_phase_id]" value="{{ $entry->project_phase_id }}"><input type="hidden" name="items[{{ $entry->id }}][main_category]" value="{{ $entry->main_category }}"><input type="hidden" name="items[{{ $entry->id }}][item_description]" value="{{ $entry->item_description }}"><input type="hidden" name="items[{{ $entry->id }}][quantity]" value="{{ $entry->quantity }}"><input type="hidden" name="items[{{ $entry->id }}][unit]" value="{{ $entry->unit }}"><input type="hidden" name="items[{{ $entry->id }}][unit_price]" value="{{ $entry->client_unit_cost }}">
 </tr>@endforeach
 </tbody></table></div>
</article>
@endforeach
</section>
@else
<section class="panel form-section"><h3>Manual Invoice Line</h3><p class="form-context">Use “Create from Estimate” from the project page for traceable progress billing.</p>
<div class="row2"><div class="field"><label>Description <span class="req">*</span></label><input name="items[0][item_description]" required></div><div class="field"><label>Phase <span class="req">*</span></label><select name="items[0][project_phase_id]" required>@foreach($project->phases as $phase)<option value="{{ $phase->id }}">{{ $phase->stage_label }}</option>@endforeach</select></div></div><input type="hidden" name="items[0][main_category]" value="materials"><div class="row3"><div class="field"><label>Quantity</label><input name="items[0][quantity]" type="number" min="0" step="0.01" value="1"></div><div class="field"><label>Unit</label><input name="items[0][unit]" value="ls"></div><div class="field"><label>Unit Price (TSh)</label><input name="items[0][unit_price]" type="number" min="0.01" step="0.01" required></div></div>
</section>
@endif
<div style="display:flex;justify-content:flex-end;gap:8px;margin:16px 0 32px"><a class="btn btn-ghost" href="{{ route('accountant.projects.show', $project) }}">Cancel</a><button class="btn btn-primary" type="submit">Create Draft Invoice</button></div></form>
@if($estimate)<script>
document.querySelectorAll('.phase-toggle').forEach(toggle=>toggle.addEventListener('change',()=>toggle.closest('article').querySelectorAll('.boq-select:not(:disabled)').forEach(box=>box.checked=toggle.checked)));
document.getElementById('selectAllRemaining').addEventListener('click',()=>document.querySelectorAll('.boq-select:not(:disabled)').forEach(box=>box.checked=true));
document.querySelectorAll('tbody tr').forEach(row=>row.addEventListener('click',event=>{if(['INPUT','BUTTON','SELECT','TEXTAREA'].includes(event.target.tagName))return;const box=row.querySelector('.boq-select:not(:disabled)');if(box)box.checked=!box.checked;}));
</script>@endif
@endsection
