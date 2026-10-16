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
@php($availableEntries = $estimate->entries->filter(fn($entry) => \App\Support\Money::compare($entry->remaining_amount, '0') > 0))
<section class="panel form-section">
 <div class="topline"><div><h3>Select Phases or Subphases</h3><p class="form-context">Select several phases or subphases in one invoice. Use the full remaining value, or enter one partial amount for a subphase and the system will allocate it across its estimate lines.</p></div><button type="button" class="btn btn-ghost" id="selectAllRemaining">Select All Remaining</button></div>
 <div class="row3" style="margin:14px 0">
  <div class="metric"><span>Estimate Total</span><strong>TSh {{ \App\Support\Money::format($estimate->total_amount) }}</strong></div>
  <div class="metric"><span>Already Invoiced / Reserved</span><strong>TSh {{ \App\Support\Money::format($estimate->claimed_amount) }}</strong></div>
  <div class="metric"><span>Available to Invoice</span><strong>TSh {{ \App\Support\Money::format($estimate->remaining_amount) }}</strong></div>
 </div>
 @forelse($availableEntries->groupBy(fn($entry) => $entry->phase?->stage_label ?? 'Other') as $phaseLabel => $phaseEntries)
 <article class="invoice-phase" style="border:1px solid var(--line);border-radius:10px;margin:12px 0;overflow:hidden">
  <div style="padding:13px 14px;background:var(--surface-2);display:flex;justify-content:space-between;gap:16px;align-items:center"><label><input type="checkbox" class="phase-toggle"> <strong>{{ $phaseLabel }}</strong></label><span>Available TSh {{ \App\Support\Money::format(\App\Support\Money::sum($phaseEntries->pluck('remaining_amount'))) }}</span></div>
  @foreach($phaseEntries->groupBy(fn($entry) => trim((string) $entry->work_section) ?: 'General Works') as $subphaseLabel => $subphaseEntries)
  @php($subphaseRemaining = \App\Support\Money::sum($subphaseEntries->pluck('remaining_amount')))
  <section class="invoice-subphase" style="padding:12px 14px;border-top:1px solid var(--line)">
   <div class="topline" style="align-items:flex-end">
    <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" class="subphase-toggle"> <strong>{{ $subphaseLabel }}</strong> <span class="muted">· Available TSh {{ \App\Support\Money::format($subphaseRemaining) }}</span></label>
    <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap"><div class="field" style="margin:0;min-width:210px"><label>Partial subphase amount</label><input class="subphase-amount" type="number" min="0.01" max="{{ $subphaseRemaining }}" step="0.01" placeholder="Enter amount"></div><button type="button" class="btn btn-ghost apply-subphase">Apply Partial</button><button type="button" class="btn btn-ghost full-subphase">Use Full Amount</button></div>
   </div>
   <div style="overflow-x:auto;margin-top:10px"><table><thead><tr><th>Select</th><th>Ref</th><th>Estimate Item</th><th>Qty</th><th>Contract</th><th>Previously Invoiced</th><th>Available</th><th>This Invoice</th></tr></thead><tbody>
   @foreach($subphaseEntries as $entry)<tr class="estimate-row" data-remaining="{{ $entry->remaining_amount }}">
    <td><input type="hidden" name="items[{{ $entry->id }}][estimate_entry_id]" value="{{ $entry->id }}"><input class="boq-select" type="checkbox" name="items[{{ $entry->id }}][selected]" value="1" @checked(old('items.'.$entry->id.'.selected'))></td>
    <td>{{ $entry->item_reference ?: '—' }}</td><td>{{ $entry->item_description }}</td><td>{{ $entry->quantity }} {{ $entry->unit }}</td><td>TSh {{ \App\Support\Money::format($entry->line_total) }}</td><td>TSh {{ \App\Support\Money::format($entry->claimed_amount) }}</td><td>TSh {{ \App\Support\Money::format($entry->remaining_amount) }}</td>
    <td><input class="claim-input" style="min-width:150px" type="number" min="0.01" max="{{ $entry->remaining_amount }}" step="0.01" name="items[{{ $entry->id }}][claim_amount]" value="{{ old('items.'.$entry->id.'.claim_amount', $entry->remaining_amount) }}"></td>
    <input type="hidden" name="items[{{ $entry->id }}][project_phase_id]" value="{{ $entry->project_phase_id }}"><input type="hidden" name="items[{{ $entry->id }}][main_category]" value="{{ $entry->main_category }}"><input type="hidden" name="items[{{ $entry->id }}][item_description]" value="{{ $entry->item_description }}"><input type="hidden" name="items[{{ $entry->id }}][quantity]" value="{{ $entry->quantity }}"><input type="hidden" name="items[{{ $entry->id }}][unit]" value="{{ $entry->unit }}"><input type="hidden" name="items[{{ $entry->id }}][unit_price]" value="{{ $entry->client_unit_cost }}">
   </tr>@endforeach
   </tbody></table></div>
  </section>
  @endforeach
 </article>
 @empty
 <div class="empty-state"><strong>This estimate has been fully invoiced.</strong><p>There is no remaining estimate value available for another invoice.</p></div>
 @endforelse
 <div style="display:flex;justify-content:flex-end;margin-top:14px"><div class="metric" style="min-width:280px"><span>Selected Invoice Total</span><strong id="selectedInvoiceTotal">TSh 0.00</strong></div></div>
</section>
@else
<section class="panel form-section"><h3>Manual Invoice Line</h3><p class="form-context">Use “Create from Estimate” from the project page for traceable progress billing.</p>
<div class="row2"><div class="field"><label>Description <span class="req">*</span></label><input name="items[0][item_description]" required></div><div class="field"><label>Phase <span class="req">*</span></label><select name="items[0][project_phase_id]" required>@foreach($project->phases as $phase)<option value="{{ $phase->id }}">{{ $phase->stage_label }}</option>@endforeach</select></div></div><input type="hidden" name="items[0][main_category]" value="materials"><div class="row3"><div class="field"><label>Quantity</label><input name="items[0][quantity]" type="number" min="0" step="0.01" value="1"></div><div class="field"><label>Unit</label><input name="items[0][unit]" value="ls"></div><div class="field"><label>Unit Price (TSh)</label><input name="items[0][unit_price]" type="number" min="0.01" step="0.01" required></div></div>
</section>
@endif
<div style="display:flex;justify-content:flex-end;gap:8px;margin:16px 0 32px"><a class="btn btn-ghost" href="{{ route('accountant.projects.show', $project) }}">Cancel</a><button class="btn btn-primary" type="submit" @disabled($estimate && $availableEntries->isEmpty())>Create Draft Invoice</button></div></form>
@if($estimate)<script>
const money = value => new Intl.NumberFormat('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}).format(value || 0);
const updateInvoiceTotal = () => {
 let total = 0;
 document.querySelectorAll('.estimate-row').forEach(row => { if (row.querySelector('.boq-select').checked) total += Number(row.querySelector('.claim-input').value || 0); });
 document.getElementById('selectedInvoiceTotal').textContent = `TSh ${money(total)}`;
 document.querySelectorAll('.invoice-subphase').forEach(section => { const boxes=[...section.querySelectorAll('.boq-select')]; section.querySelector('.subphase-toggle').checked=boxes.length>0&&boxes.every(box=>box.checked); });
 document.querySelectorAll('.invoice-phase').forEach(section => { const boxes=[...section.querySelectorAll('.boq-select')]; section.querySelector('.phase-toggle').checked=boxes.length>0&&boxes.every(box=>box.checked); });
};
const selectRows = (container, selected) => { container.querySelectorAll('.estimate-row').forEach(row => { row.querySelector('.boq-select').checked=selected; if(selected) row.querySelector('.claim-input').value=row.dataset.remaining; }); updateInvoiceTotal(); };
document.querySelectorAll('.phase-toggle').forEach(toggle=>toggle.addEventListener('change',()=>selectRows(toggle.closest('.invoice-phase'), toggle.checked)));
document.querySelectorAll('.subphase-toggle').forEach(toggle=>toggle.addEventListener('change',()=>selectRows(toggle.closest('.invoice-subphase'), toggle.checked)));
document.querySelectorAll('.full-subphase').forEach(button=>button.addEventListener('click',()=>selectRows(button.closest('.invoice-subphase'), true)));
document.querySelectorAll('.apply-subphase').forEach(button=>button.addEventListener('click',()=>{
 const section=button.closest('.invoice-subphase'); let amount=Number(section.querySelector('.subphase-amount').value||0); const maximum=[...section.querySelectorAll('.estimate-row')].reduce((sum,row)=>sum+Number(row.dataset.remaining),0);
 if(amount<=0||amount>maximum){ section.querySelector('.subphase-amount').setCustomValidity(`Enter an amount between 0.01 and ${money(maximum)}.`); section.querySelector('.subphase-amount').reportValidity(); return; }
 section.querySelector('.subphase-amount').setCustomValidity('');
 section.querySelectorAll('.estimate-row').forEach(row=>{ const allocated=Math.min(amount,Number(row.dataset.remaining)); row.querySelector('.boq-select').checked=allocated>0; row.querySelector('.claim-input').value=allocated>0?allocated.toFixed(2):row.dataset.remaining; amount-=allocated; }); updateInvoiceTotal();
}));
document.getElementById('selectAllRemaining')?.addEventListener('click',()=>selectRows(document, true));
document.querySelectorAll('.boq-select,.claim-input').forEach(input=>input.addEventListener('change',()=>{ if(input.classList.contains('claim-input')&&Number(input.value)>0) input.closest('.estimate-row').querySelector('.boq-select').checked=true; updateInvoiceTotal(); }));
document.querySelectorAll('.estimate-row').forEach(row=>row.addEventListener('click',event=>{if(['INPUT','BUTTON','SELECT','TEXTAREA','LABEL'].includes(event.target.tagName))return;const box=row.querySelector('.boq-select');box.checked=!box.checked;updateInvoiceTotal();}));
document.querySelector('form').addEventListener('submit',()=>document.querySelectorAll('.estimate-row').forEach(row=>{if(!row.querySelector('.boq-select').checked)row.querySelectorAll('input').forEach(input=>input.disabled=true);}));
updateInvoiceTotal();
</script>@endif
@endsection
