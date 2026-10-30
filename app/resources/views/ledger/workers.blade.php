@extends('layouts.app')
@section('title', $project->name.' · Workers')
@section('content')
@include('partials.ledger.styles')
@php
  $money = \App\Support\Money::class;
  $canEdit = auth()->user()->can('manage-workers', $project);
@endphp
<div class="lg-head">
  <div><h2>Workers</h2><p>{{ $project->name }} · fundis and subcontractors. Register a worker once (with the contract amount, if there is one), then pick them when recording a labour payment.</p></div>
  @if($canEdit)<div class="lg-actions"><button class="btn btn-primary" type="button" onclick="document.getElementById('workerDialog').showModal()">+ Register Worker</button></div>@endif
</div>
@if ($errors->any())<div class="flash error"><ul style="margin:0;padding-left:20px;">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<div class="lg-cards">
  <div class="lg-card accent"><div class="k">Workers</div><div class="v">{{ $workers->count() }}</div><div class="s">{{ $workers->filter(fn ($w) => (float) $w['contract'] > 0)->count() }} with a contract</div></div>
  <div class="lg-card"><div class="k">Recorded labour</div><div class="v">TSh {{ $money::format($money::sum($workers->pluck('recorded'))) }}</div></div>
  <div class="lg-card good"><div class="k">Paid</div><div class="v">TSh {{ $money::format($money::sum($workers->pluck('paid'))) }}</div></div>
</div>

<div class="lg-scroll" style="max-height:none">
  <table class="lg-table">
    <thead><tr><th>Worker</th><th>Type / scope</th><th>Contact</th><th class="amt">Contract</th><th class="amt">Recorded</th><th class="amt">Paid</th><th class="amt">Balance</th><th style="min-width:140px">Progress</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse($workers as $row)
      @php $worker = $row['worker']; @endphp
      <tr>
        <td><b>{{ $worker->fundi_subcontractor }}</b><span class="muted">{{ $row['lines'] }} payments</span></td>
        <td>{{ $labourTypes[$worker->labour_type] ?? '—' }}@if($worker->scope_of_work)<span class="muted">{{ \Illuminate\Support\Str::limit($worker->scope_of_work, 80) }}</span>@endif</td>
        <td>{{ $worker->contact ?: '—' }}</td>
        <td class="amt">{{ (float) $row['contract'] > 0 ? $money::format($row['contract']) : 'No contract' }}</td>
        <td class="amt">{{ $money::format($row['recorded']) }}</td>
        <td class="amt">{{ $money::format($row['paid']) }}</td>
        <td class="amt">{{ $row['balance'] !== null ? $money::format($row['balance']) : '—' }}</td>
        <td>@if($row['progress'] !== null)<div class="lg-meter" style="margin-top:3px"><span class="{{ (float) $row['progress'] > 100 ? 'over' : '' }}" style="width:{{ min(100, (float) $row['progress']) }}%"></span></div><span class="muted">{{ $money::format($row['progress']) }}% of contract</span>@else<span class="muted">—</span>@endif</td>
        <td><span class="dot-status {{ $worker->status === 'completed' ? 'zero' : 'active' }}">{{ ucfirst($worker->status) }}</span></td>
        <td><div class="lg-row-actions">
          <a class="lg-link" href="{{ route('ledger.expenses', [$project, 'worker' => $worker->id]) }}">Payments</a>
          @can('record-expense', $project)<a class="lg-link" href="{{ route('ledger.expenses.create', [$project]) }}?worker={{ $worker->id }}">Record payment</a>@endcan
          @if($canEdit)
            @php
              $payload = ['id' => $worker->id, 'name' => $worker->fundi_subcontractor, 'type' => $worker->labour_type, 'scope' => $worker->scope_of_work, 'contact' => $worker->contact,
                  'value' => (float) $worker->agreed_value > 0 ? (float) $worker->agreed_value : '', 'start' => $worker->start_date?->toDateString(), 'end' => $worker->expected_completion?->toDateString(),
                  'status' => $worker->status, 'url' => route('ledger.workers.update', [$project, $worker])];
            @endphp
            <button class="lg-link" type="button" data-edit-worker="{{ json_encode($payload) }}">Edit</button>
          @endif
        </div></td>
      </tr>
    @empty
      <tr><td colspan="10" class="lg-empty">No workers registered yet.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>

@if($canEdit)
<dialog class="lg-dialog" id="workerDialog" style="max-width:520px"><form method="POST" action="{{ route('ledger.workers.store', $project) }}" id="workerForm">@csrf
  <input type="hidden" name="_method" value="POST" id="workerMethod">
  <h3 id="workerTitle">Register worker</h3>
  <div class="field"><label for="w_name">Name <span class="req">*</span></label><input id="w_name" name="fundi_subcontractor" maxlength="150" required placeholder="e.g. Henry (builder)"></div>
  <div class="row2"><div class="field"><label for="w_type">Type</label><select id="w_type" name="labour_type"><option value="">—</option>@foreach($labourTypes as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select></div>
  <div class="field"><label for="w_contact">Phone / contact</label><input id="w_contact" name="contact" maxlength="100"></div></div>
  <div class="field"><label for="w_scope">Scope of work</label><input id="w_scope" name="scope_of_work" maxlength="1000" placeholder="e.g. Blockwork ground floor"></div>
  <div class="field"><label for="w_value">Contract amount (TSh) — leave empty if paid per job</label><input type="number" step="0.01" min="0" id="w_value" name="agreed_value"></div>
  <div class="row2"><div class="field"><label for="w_start">Start</label><input type="date" id="w_start" name="start_date"></div><div class="field"><label for="w_end">Expected completion</label><input type="date" id="w_end" name="expected_completion"></div></div>
  <div class="field" id="w_status_field" hidden><label for="w_status">Status</label><select id="w_status" name="status"><option value="active">Active</option><option value="completed">Completed</option></select></div>
  <div style="display:flex;gap:8px;justify-content:flex-end"><button class="btn btn-ghost" type="button" onclick="this.closest('dialog').close()">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
</form></dialog>
<script>
document.querySelectorAll('[data-edit-worker]').forEach(button => button.addEventListener('click', () => {
  const w = JSON.parse(button.dataset.editWorker), f = document.getElementById('workerForm');
  f.action = w.url; document.getElementById('workerMethod').value = 'PUT'; document.getElementById('workerTitle').textContent = 'Edit worker';
  f.fundi_subcontractor.value = w.name || ''; f.labour_type.value = w.type || ''; f.scope_of_work.value = w.scope || ''; f.contact.value = w.contact || '';
  f.agreed_value.value = w.value || ''; f.start_date.value = w.start || ''; f.expected_completion.value = w.end || '';
  document.getElementById('w_status_field').hidden = false; f.status.value = w.status === 'completed' ? 'completed' : 'active';
  document.getElementById('workerDialog').showModal();
}));
</script>
@endif
@endsection
