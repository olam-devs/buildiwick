@extends('layouts.app')
@section('title', $project->name.' · Opening Records')
@section('content')
<a class="back-link" href="{{ route('accountant.projects.show', $project) }}">&larr; Back to {{ $project->name }}</a>
<div class="topline">
  <div><h2>Opening Records</h2><p>{{ $project->project_code }} · {{ $project->client?->name ?? 'No client linked' }} · bring this ongoing project's past expenses, invoices and client payments into Buildiwick</p></div>
</div>

@if ($errors->any() && ! $errors->has('records_file'))<div class="flash error"><ul style="margin:0;padding-left:20px;">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<section class="panel form-section">
  <h3>Before you import</h3>
  <ul class="onboarding-checklist">
    <li><span class="dot-status {{ $hasIssuedEstimate ? 'active' : 'attn' }}">{{ $hasIssuedEstimate ? 'Estimate issued' : 'No issued estimate yet' }}</span></li>
    <li><span class="dot-status {{ $hasApprovedBudget ? 'active' : 'attn' }}">{{ $hasApprovedBudget ? 'Internal budget approved' : 'No approved internal budget yet' }}</span></li>
    <li><span class="dot-status {{ $project->client_id ? 'active' : 'attn' }}">{{ $project->client_id ? 'Client linked' : 'No client linked (needed for invoices and client payments)' }}</span></li>
  </ul>
  <p class="form-context">Import after the estimate and internal budget are in place so expenses can be linked to budget lines. The template is built for this project: its phases, approved budget lines, categories, units and payment methods appear as dropdowns.</p>
</section>

<section class="panel form-section">
  <h3>Import workbook</h3>
  <p class="form-context">Imported rows become normal approved expenses with their payments, issued client invoices and client receipts, marked as historical onboarding records. Any unpaid balance becomes an approved payable. Nothing is saved if any row has a problem.</p>
  <div class="estimate-actions">
    <a class="btn btn-ghost" href="{{ route('accountant.onboarding.template', $project) }}">Download Template</a>
    <form method="POST" action="{{ route('accountant.onboarding.store', $project) }}" enctype="multipart/form-data" class="onboarding-form" onsubmit="return confirm('Import these records into {{ $project->name }}?');">@csrf
      <div class="field"><label for="records_file">Filled workbook (.xlsx) <span class="req">*</span></label><input id="records_file" type="file" name="records_file" accept=".xlsx" required></div>
      <div class="field"><label for="notes">Notes</label><input id="notes" name="notes" maxlength="2000" placeholder="e.g. Records from the 2026 interim tracker"></div>
      <button class="btn btn-primary" type="submit">Import Records</button>
    </form>
  </div>
  @error('records_file')<div class="flash error"><ul style="margin:0;padding-left:20px;">@foreach ($errors->get('records_file') as $message)<li>{{ $message }}</li>@endforeach</ul></div>@enderror
</section>

<section class="panel form-section">
  <h3>Import history</h3>
  <div class="tablewrap"><table style="min-width:900px"><thead><tr><th>Import</th><th>Imported</th><th>Expenses</th><th>Paid</th><th>Invoices</th><th>Client Payments</th><th>Status</th><th>Action</th></tr></thead><tbody>
  @forelse ($imports as $import)
    <tr>
      <td>{{ $import->import_code }}<small class="cell-note">{{ $import->original_filename }}</small>@if($import->notes)<small class="cell-note">{{ $import->notes }}</small>@endif</td>
      <td>{{ $import->created_at->format('d M Y H:i') }}<small class="cell-note">{{ $import->importedBy?->name ?? '—' }}</small></td>
      <td class="amt">{{ $import->expense_count }} · {{ \App\Support\Money::format($import->expense_total) }}</td>
      <td class="amt">{{ \App\Support\Money::format($import->expense_paid_total) }}</td>
      <td class="amt">{{ $import->invoice_count }} · {{ \App\Support\Money::format($import->invoice_total) }}</td>
      <td class="amt">{{ $import->client_payment_count }} · {{ \App\Support\Money::format($import->client_payment_total) }}</td>
      <td>@if($import->undone_at)<span class="dot-status zero">Undone</span><small class="cell-note">{{ $import->undone_at->format('d M Y') }} · {{ $import->undoneBy?->name }} · {{ $import->undo_reason }}</small>@else<span class="dot-status active">Imported</span>@endif</td>
      <td>
        @if($import->stored_path)<a href="{{ route('accountant.onboarding.download', [$project, $import]) }}">File</a>@endif
        @unless($import->undone_at)
          <form method="POST" action="{{ route('accountant.onboarding.undo', [$project, $import]) }}" onsubmit="const reason=prompt('Why is this import being undone? All its records will be removed.');if(!reason)return false;this.undo_reason.value=reason;return true;">@csrf<input type="hidden" name="undo_reason"><button class="link-button" type="submit">Undo</button></form>
        @endunless
      </td>
    </tr>
  @empty
    <tr><td colspan="8">No opening records have been imported for this project.</td></tr>
  @endforelse
  </tbody></table></div>
</section>
<style>.onboarding-checklist{list-style:none;padding:0;margin:0 0 10px;display:flex;gap:18px;flex-wrap:wrap}.onboarding-form{display:flex;gap:8px;align-items:end;flex-wrap:wrap}.onboarding-form .field{margin:0;min-width:240px}.cell-note{display:block;color:var(--ink-dim);font-size:11px;margin-top:3px}.link-button{background:none;border:0;color:#b42318;cursor:pointer;padding:0;margin-left:8px}</style>
@endsection
