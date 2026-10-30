@extends('layouts.app')
@section('title', $project->name.' · Expenditure')
@section('content')
@include('partials.ledger.styles')
@php
  $money = \App\Support\Money::class;
  $categories = \App\Services\LedgerAnalyticsService::CATEGORIES;
  $colors = \App\Services\LedgerAnalyticsService::CATEGORY_COLORS;
  $canConfirm = auth()->user()->can('confirm-payments', $project);
  $canDeny = auth()->user()->can('deny-expense', $project);
  $denied = ($filters['status'] ?? '') === 'denied';
  $showTotals = auth()->user()->can('view-analytics', $project);
  $isDirector = auth()->user()->hasRole('admin');
  $canDelete = $isDirector || auth()->user()->permits('edit_any_line') || auth()->user()->can('record-expense', $project);
  $bulk = ! $denied && ($canConfirm || $canDeny || $canDelete);
  $statusLabels = ['paid' => 'Paid', 'part_paid' => 'Part paid', 'unpaid' => 'To confirm'];
  $query = fn (array $change) => route('ledger.expenses', array_merge([$project], array_filter(array_merge(request()->except('page'), $change), fn ($v) => $v !== null && $v !== '')));
@endphp
<div class="lg-head">
  <div><h2>Expenditure</h2><p>{{ $project->name }} · {{ $project->project_code }}@if($project->planned_start_date || $project->expected_completion_date) · {{ $project->planned_start_date?->format('d M Y') ?? '…' }} – {{ $project->expected_completion_date?->format('d M Y') ?? '…' }}@endif</p></div>
  <div class="lg-actions">
    @can('record-expense', $project)<a class="btn btn-primary" href="{{ route('ledger.expenses.create', $project) }}">+ Record Expense</a>@endcan
    @can('export-ledger', $project)<a class="btn btn-ghost" href="{{ route('ledger.expenses.export', array_merge([$project], request()->except('page'))) }}">Download Excel</a>@endcan
  </div>
</div>

@if ($errors->any())<div class="flash error"><ul style="margin:0;padding-left:20px;">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<div class="lg-chips">
  <a class="lg-chip {{ empty($filters['category']) ? 'on' : '' }}" href="{{ $query(['category' => null]) }}">All categories</a>
  @foreach($categories as $code => $label)<a class="lg-chip {{ ($filters['category'] ?? '') === $code ? 'on' : '' }}" href="{{ $query(['category' => $code]) }}"><i style="background:{{ $colors[$code] }}"></i>{{ $label }}</a>@endforeach
</div>

<form method="GET" class="lg-filters" id="filters">
  @if(!empty($filters['category']))<input type="hidden" name="category" value="{{ $filters['category'] }}">@endif
  <div class="field" style="min-width:200px;flex:1"><label for="q">Search</label><input type="search" id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Item, ID, receipt no." autocomplete="off"></div>
  <div class="field"><label for="phase">Phase</label><select id="phase" name="phase"><option value="">All phases</option>@foreach($tree->flatten() as $node)<option value="{{ $node['phase']->id }}" @selected((string) ($filters['phase'] ?? '') === (string) $node['phase']->id)>{{ str_repeat('— ', $node['depth'] - 1) }}{{ $node['label'] }}</option>@endforeach</select></div>
  <div class="field"><label for="status">Status</label><select id="status" name="status"><option value="">Any</option>@foreach(['to_confirm' => 'To confirm', 'part_paid' => 'Part paid', 'paid' => 'Paid', 'denied' => 'Denied'] as $code => $label)<option value="{{ $code }}" @selected(($filters['status'] ?? '') === $code)>{{ $label }}</option>@endforeach</select></div>
  <div class="field"><label for="subcategory">Subcategory</label><select id="subcategory" name="subcategory"><option value="">Any</option>@foreach($subcategories as $code => $label)<option value="{{ $code }}" @selected(($filters['subcategory'] ?? '') === $code)>{{ $label }}</option>@endforeach</select></div>
  @if($workers->isNotEmpty())<div class="field"><label for="worker">Worker</label><select id="worker" name="worker"><option value="">Any</option>@foreach($workers as $id => $name)<option value="{{ $id }}" @selected((string) ($filters['worker'] ?? '') === (string) $id)>{{ $name }}</option>@endforeach</select></div>@endif
  <div class="field" style="min-width:135px"><label for="from">From</label><input type="date" id="from" name="from" value="{{ $filters['from'] ?? '' }}"></div>
  <div class="field" style="min-width:135px"><label for="to">To</label><input type="date" id="to" name="to" value="{{ $filters['to'] ?? '' }}"></div>
  <div class="field" style="min-width:130px"><label for="sort">Sort</label><select id="sort" name="sort"><option value="newest">Newest first</option><option value="oldest" @selected(($filters['sort'] ?? '') === 'oldest')>Oldest first</option><option value="amount" @selected(($filters['sort'] ?? '') === 'amount')>Largest amount</option></select></div>
  @if(array_filter($filters))<a class="btn btn-ghost" href="{{ route('ledger.expenses', $project) }}">Clear filters</a>@endif
</form>

@include('ledger.partials.team-kpis')
@if(! $denied && $showTotals)
<div class="lg-cards">
  <div class="lg-card accent"><div class="k">Confirmed spend</div><div class="v">TSh {{ $money::format($totals['paid']) }}</div><div class="s">{{ number_format($totals['count']) }} lines in this view</div></div>
  <div class="lg-card warn"><div class="k">Awaiting confirmation</div><div class="v">TSh {{ $money::format($totals['to_confirm']) }}</div><div class="s">Not counted as spend until confirmed</div></div>
</div>
@elseif($denied)
<div class="flash">Denied lines are kept for the record but do not count in any total. {{ number_format($totals['count']) }} denied line(s).</div>
@endif

<div class="lg-scroll">
  <table class="lg-table lg-cards-sm">
    <thead><tr>@if($bulk)<th class="lg-check"><input type="checkbox" id="checkAll" aria-label="Select all lines on this page"></th>@endif<th>ID / Date</th><th>Category</th><th>Item / Description</th><th>Phase</th><th class="amt">Qty</th><th class="amt">Unit Cost</th><th class="amt">Total</th><th class="amt">{{ $denied ? 'Denied' : 'Paid' }}</th><th></th></tr></thead>
    <tbody>
    @forelse($lines as $line)
      @php($status = $line->ledger_status ?? $line->payment_status)
      <tr title="Recorded by {{ $line->requestedBy?->name ?? '—' }}">
        @if($bulk)<td class="lg-check" data-label="Select"><input type="checkbox" class="rowCheck" value="{{ $line->id }}" aria-label="Select {{ $line->request_code }}"></td>@endif
        <td data-label="ID / Date" style="white-space:nowrap"><span class="code">{{ $line->request_code }}</span><span class="muted">{{ $line->requested_at?->format('d M Y') }}</span></td>
        <td data-label="Category" style="min-width:140px"><span class="lg-cat"><i style="background:{{ $colors[$line->main_category] ?? '#999' }}"></i>{{ $categories[$line->main_category] ?? $line->main_category }}</span>@if($line->subcategory)<span class="muted">{{ $subcategories[$line->subcategory] ?? $line->subcategory }}</span>@endif</td>
        <td data-label="Item" style="min-width:200px"><b style="font-weight:600">{{ $line->item_description }}</b>@if($line->labourAgreement)<span class="muted">Worker: {{ $line->labourAgreement->fundi_subcontractor }}</span>@endif @if($line->notes)<span class="muted" title="{{ $line->notes }}">{{ \Illuminate\Support\Str::limit(\Illuminate\Support\Str::after($line->notes, 'Evidence: '), 48) }}</span>@endif</td>
        <td data-label="Phase" style="min-width:110px">{{ $tree->path($line->project_phase_id) }}</td>
        <td data-label="Qty" class="amt">{{ $line->quantity !== null ? rtrim(rtrim($line->quantity, '0'), '.') : '—' }}<span class="muted">{{ $units[$line->unit] ?? $line->unit }}</span></td>
        <td data-label="Unit cost" class="amt">{{ $line->unit_cost !== null ? $money::format($line->unit_cost) : '—' }}</td>
        <td data-label="Total" class="amt"><b>{{ $money::format($line->total_amount) }}</b></td>
        @if($denied)
          <td data-label="Denied" class="amt"><span class="lg-pill denied">Denied</span><span class="muted">{{ $line->latestApproval?->notes }}</span><span class="muted">{{ $line->latestApproval?->decidedBy?->name }} · {{ $line->latestApproval?->decided_at?->format('d M Y') }}</span></td>
          <td class="lg-btn-cell"><div class="lg-btns">@if($canDeny)<form method="POST" action="{{ route('ledger.expenses.restore', [$project, $line]) }}">@csrf<button class="lg-btn" type="submit">Restore</button></form>@endif</div></td>
        @else
          <td data-label="Paid" class="amt">{{ $money::format($line->paid_to_date) }}<span style="display:block;margin-top:3px"><span class="lg-pill {{ $status }}">{{ $statusLabels[$status] }}</span></span></td>
          <td class="lg-btn-cell"><div class="lg-btns">
            @if($canConfirm && $status !== 'paid')<button class="lg-btn ok" type="button" data-confirm-url="{{ route('ledger.expenses.confirm', [$project, $line]) }}" data-code="{{ $line->request_code }}" data-item="{{ $line->item_description }}" data-total="{{ $line->total_amount }}" data-outstanding="{{ $line->approved_outstanding_amount }}" data-paid="{{ $line->paid_to_date }}">✓ Confirm</button>@endif
            @if($line->payments->isEmpty() || $isDirector)
              @can('edit-ledger-line', $line)<a class="lg-btn" href="{{ route('ledger.expenses.edit', [$project, $line]) }}">Edit</a>@endcan
            @endif
            @if($canDeny && $line->payments->isEmpty())<button class="lg-btn warn" type="button" data-deny-url="{{ route('ledger.expenses.deny', [$project, $line]) }}" data-code="{{ $line->request_code }}" data-item="{{ $line->item_description }}">Deny</button>@endif
            @if($line->payments->isEmpty() || $isDirector)
              @can('edit-ledger-line', $line)<form method="POST" action="{{ route('ledger.expenses.destroy', [$project, $line]) }}" onsubmit="return confirm('Delete {{ $line->request_code }}{{ $line->payments->isNotEmpty() ? ' and its payment' : '' }}?');">@csrf @method('DELETE')<button class="lg-btn bad" type="submit">Delete</button></form>@endcan
            @endif
          </div></td>
        @endif
      </tr>
    @empty
      <tr><td colspan="{{ $bulk ? 10 : 9 }}" class="lg-empty">No expenses match this view.</td></tr>
    @endforelse
    </tbody>
    @if($lines->total() && ! $denied && $showTotals)<tfoot><tr><td colspan="{{ $bulk ? 7 : 6 }}">Total of {{ number_format($totals['count']) }} lines in this view</td><td class="amt">{{ $money::format($totals['recorded']) }}</td><td class="amt">{{ $money::format($totals['paid']) }}</td><td></td></tr></tfoot>@endif
  </table>
</div>
@if($lines->hasPages())<div class="lg-pager">Page {{ $lines->currentPage() }} of {{ $lines->lastPage() }} @if(!$lines->onFirstPage())<a class="btn btn-ghost" href="{{ $lines->previousPageUrl() }}">← Previous</a>@endif @if($lines->hasMorePages())<a class="btn btn-ghost" href="{{ $lines->nextPageUrl() }}">Next →</a>@endif</div>@endif

@if(! $denied && $showTotals)
<section class="lg-panel" style="margin-top:16px">
  <h3>Spend by phase</h3><p class="hint">Totals for the lines in this view, by phase and main category. Each phase includes its subphases.</p>
  <div class="tablewrap" style="border:0"><table class="lg-table lg-tree"><thead><tr><th>Phase</th>@foreach($categories as $code => $label)<th class="amt">{{ $label }}</th>@endforeach<th class="amt">Total</th><th class="amt">Budget</th></tr></thead><tbody>
    @foreach($phaseRows as $row)
      <tr class="{{ $row['depth'] === 1 ? 'top' : '' }}"><td class="phase d{{ $row['depth'] }}"><a href="{{ $query(['phase' => $row['phase']->id]) }}" style="color:inherit;text-decoration:none">{{ $row['label'] }}</a></td>
        @foreach(array_keys($categories) as $code)<td class="amt">{{ (float) $row['categories'][$code]['recorded'] ? $money::format($row['categories'][$code]['recorded']) : '—' }}</td>@endforeach
        <td class="amt"><b>{{ $money::format($row['recorded']) }}</b></td><td class="amt">{{ (float) $row['budget'] ? $money::format($row['budget']) : '—' }}</td></tr>
    @endforeach
  </tbody></table></div>
</section>
@endif

@if($canConfirm)@include('ledger.partials.confirm-dialog')@endif
@if($bulk)
<div class="lg-bulkbar" id="bulkBar" hidden>
  <span><b id="bulkCount">0</b> selected</span>
  <button type="button" class="lg-btn" id="bulkAll">Select all on page</button>
  <span class="grow"></span>
  @if($canConfirm)<button type="button" class="lg-btn ok" data-bulk="confirm">✓ Confirm</button>@endif
  @if($canDeny)<button type="button" class="lg-btn warn" data-bulk="deny">Deny</button>@endif
  @if($canDelete)<button type="button" class="lg-btn bad" data-bulk="delete">Delete</button>@endif
  <button type="button" class="lg-btn" id="bulkClear">Clear</button>
</div>
<form method="POST" action="{{ route('ledger.expenses.bulk', $project) }}" id="bulkForm">@csrf<input type="hidden" name="action" id="bulkAction"></form>
@if($canConfirm)
<dialog class="lg-dialog" id="bulkConfirmDialog"><div>
  <h3>Confirm <span data-bulk-count></span> line(s)</h3>
  <p class="form-context" style="margin:0">Each selected line is confirmed as paid in full for what is still open on it. For a part payment, use that line's own ✓ Confirm button. Lines already paid are skipped.</p>
  <div class="row2"><div class="field"><label for="bulk_payment_date">Payment date <span class="req">*</span></label><input type="date" id="bulk_payment_date" name="payment_date" form="bulkForm" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" required></div>
  <div class="field"><label for="bulk_payment_method">Method</label><select id="bulk_payment_method" name="payment_method" form="bulkForm"><option value="">Not recorded</option>@foreach($methods as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select></div></div>
  <div class="row2"><div class="field"><label for="bulk_receipt">Receipt no. (optional)</label><input id="bulk_receipt" name="invoice_receipt_no" form="bulkForm" maxlength="255"></div>
  <div class="field"><label for="bulk_reference">Reference (optional)</label><input id="bulk_reference" name="transaction_reference" form="bulkForm" maxlength="255"></div></div>
  <div style="display:flex;gap:8px;justify-content:flex-end"><button class="btn btn-ghost" type="button" onclick="this.closest('dialog').close()">Cancel</button><button class="btn btn-primary" type="button" data-bulk-submit="confirm">Confirm selected</button></div>
</div></dialog>
@endif
@if($canDeny)
<dialog class="lg-dialog" id="bulkDenyDialog"><div>
  <h3>Deny <span data-bulk-count></span> line(s)</h3>
  <div class="field"><label for="bulk_reason">Why are they denied? <span class="req">*</span></label><input id="bulk_reason" name="reason" form="bulkForm" maxlength="1000" required placeholder="e.g. Duplicates of earlier lines"></div>
  <p class="form-context" style="margin:0">Paid lines are skipped. Denied lines can be restored later.</p>
  <div style="display:flex;gap:8px;justify-content:flex-end"><button class="btn btn-ghost" type="button" onclick="this.closest('dialog').close()">Cancel</button><button class="btn btn-primary" type="button" data-bulk-submit="deny" style="background:var(--danger);border-color:var(--danger)">Deny selected</button></div>
</div></dialog>
@endif
<script>
(() => {
  const boxes = [...document.querySelectorAll('.rowCheck')], all = document.getElementById('checkAll'), bar = document.getElementById('bulkBar');
  const form = document.getElementById('bulkForm'), chosen = () => boxes.filter(b => b.checked);
  const sync = () => {
    const n = chosen().length;
    bar.hidden = n === 0;
    document.getElementById('bulkCount').textContent = n;
    document.querySelectorAll('[data-bulk-count]').forEach(e => e.textContent = n);
    if (all) { all.checked = n > 0 && n === boxes.length; all.indeterminate = n > 0 && n < boxes.length; }
    boxes.forEach(b => b.closest('tr').classList.toggle('lg-selected', b.checked));
  };
  boxes.forEach(b => b.addEventListener('change', sync));
  if (all) all.addEventListener('change', () => { boxes.forEach(b => b.checked = all.checked); sync(); });
  document.getElementById('bulkAll').addEventListener('click', () => { boxes.forEach(b => b.checked = true); sync(); });
  document.getElementById('bulkClear').addEventListener('click', () => { boxes.forEach(b => b.checked = false); sync(); });
  const send = action => {
    form.querySelectorAll('input[name="ids[]"]').forEach(i => i.remove());
    chosen().forEach(b => { const i = document.createElement('input'); i.type = 'hidden'; i.name = 'ids[]'; i.value = b.value; form.append(i); });
    document.getElementById('bulkAction').value = action;
    form.submit();
  };
  document.querySelectorAll('[data-bulk]').forEach(b => b.addEventListener('click', () => {
    const action = b.dataset.bulk;
    if (action === 'confirm') { document.getElementById('bulkConfirmDialog').showModal(); return; }
    if (action === 'deny') { document.getElementById('bulkDenyDialog').showModal(); document.getElementById('bulk_reason').focus(); return; }
    if (window.confirm('Delete ' + chosen().length + ' selected line(s)? Lines that cannot be deleted are skipped.')) send('delete');
  }));
  document.querySelectorAll('[data-bulk-submit]').forEach(b => b.addEventListener('click', () => {
    const reason = document.getElementById('bulk_reason');
    if (b.dataset.bulkSubmit === 'deny' && !reason.value.trim()) { reason.reportValidity(); return; }
    send(b.dataset.bulkSubmit);
  }));
})();
</script>
@endif
@if($canDeny)
<dialog class="lg-dialog" id="denyDialog"><form method="POST" id="denyForm">@csrf
  <h3>Deny expense</h3>
  <p class="form-context" id="denyWhat" style="margin:0"></p>
  <div class="field"><label for="deny_reason">Why is it denied? <span class="req">*</span></label><input id="deny_reason" name="reason" maxlength="1000" required placeholder="e.g. Duplicate of an earlier line, not for this project"></div>
  <p class="form-context" style="margin:0">The line stays visible under the Denied filter but no longer counts in any total. It can be restored.</p>
  <div style="display:flex;gap:8px;justify-content:flex-end"><button class="btn btn-ghost" type="button" onclick="this.closest('dialog').close()">Cancel</button><button class="btn btn-primary" type="submit" style="background:var(--danger);border-color:var(--danger)">Deny</button></div>
</form></dialog>
<script>document.querySelectorAll('[data-deny-url]').forEach(b => b.addEventListener('click', () => { const f = document.getElementById('denyForm'); f.action = b.dataset.denyUrl; document.getElementById('denyWhat').textContent = b.dataset.code + ' · ' + b.dataset.item; document.getElementById('denyDialog').showModal(); document.getElementById('deny_reason').focus(); }));</script>
@endif
<script>
// Filters apply as soon as they change; search applies after you stop typing.
(() => {
  const form = document.getElementById('filters');
  form.querySelectorAll('select, input[type=date]').forEach(el => el.addEventListener('change', () => form.submit()));
  const q = document.getElementById('q'); let timer;
  q.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => form.submit(), 600); });
  if (q.value) { q.focus(); q.setSelectionRange(q.value.length, q.value.length); }
})();
</script>
@endsection
