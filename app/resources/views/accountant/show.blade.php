@extends('layouts.app')
@section('title', ($expenseRequest->request_batch_code ?: $expenseRequest->request_code).' · Accountant')
@section('content')
<a class="back-link" href="{{ route('accountant.requests.index',['project_id'=>$expenseRequest->project_id,'filter'=>'all']) }}">&larr; Back to {{ $expenseRequest->project->name }} Approved Payments</a>
<div class="topline"><div><h2>{{ $expenseRequest->request_batch_code ?: $expenseRequest->request_code }}</h2><p>{{ $expenseRequest->project->name }} · {{ $batchItems->count() }} approved item(s) · {{ $supplierGroups->count() }} supplier group(s)</p></div><span class="status-pill payment-{{ \App\Support\Money::compare($batchTotals['outstanding'],'0')===0?'paid':(\App\Support\Money::compare($batchTotals['paid'],'0')>0?'part_paid':'unpaid') }}">Request Payment Position</span></div>

@if($errors->any())<div class="flash error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<section class="panel form-section"><h3>Approved Request Total</h3><div class="tiles"><div class="tile"><div class="label">Approved Request Total</div><div class="value">TSh {{ \App\Support\Money::format($batchTotals['approved']) }}</div></div><div class="tile"><div class="label">Total Paid</div><div class="value">TSh {{ \App\Support\Money::format($batchTotals['paid']) }}</div></div><div class="tile"><div class="label">Total Outstanding</div><div class="value">TSh {{ \App\Support\Money::format($batchTotals['outstanding']) }}</div></div></div></section>

<section class="panel form-section"><h3>Supplier Groups, Approved Items and Payments</h3><p class="form-context">Accounts sees only the PM-approved commercial position. Each supplier group contains its supplier details, approved items, payment history and payment controls.</p>
@foreach($supplierGroups as $groupKey => $group)
  @php($first=$group->first())
  @php($supplier=$first->supplierPayee)
  @php($approval=$first->latestPmApproval)
  @php($groupApproved=\App\Support\Money::sum($group->map(fn($item)=>$item->approved_payable_amount)))
  @php($groupPaid=\App\Support\Money::sum($group->map(fn($item)=>$item->paid_to_date)))
  @php($groupOutstanding=\App\Support\Money::difference($groupApproved,$groupPaid))
  @php($groupId='supplier-group-'.md5((string)$groupKey))
  @php($paymentInstructions=$group->map(fn($item)=>$item->latestPmApproval?->payment_destination_snapshot ?: $item->commercial_payment_destination)->filter()->unique()->join(' · '))
  @php($paymentMethod=$group->map(fn($item)=>$item->latestPmApproval?->payment_method_snapshot ?: $item->commercial_payment_method)->filter()->unique()->map(fn($method)=>ucwords(str_replace('_',' ',$method)))->join(' / '))
  <article class="supplier-group" id="{{ $groupId }}">
    <header class="supplier-group-head"><div><span class="supplier-kicker">Supplier Group</span><h3>{{ $supplier?->name ?? $approval?->supplier_name_snapshot ?? 'Supplier not recorded' }}</h3><p>{{ ucwords(str_replace('_',' ',$supplier?->type ?? $approval?->supplier_type_snapshot ?? 'supplier')) }}</p></div><div class="supplier-group-position"><strong>TSh {{ \App\Support\Money::format($groupOutstanding) }}</strong><span>Outstanding · {{ $group->count() }} item(s)</span></div></header>
    <div class="supplier-profile-grid">
      <div><span>Contact Person</span><strong>{{ $supplier?->contact_person ?: $approval?->supplier_contact_snapshot ?: '—' }}</strong></div>
      <div><span>Phone</span><strong>{{ $supplier?->phone ?: $approval?->supplier_phone_snapshot ?: '—' }}</strong></div>
      <div><span>Email</span><strong>{{ $supplier?->email ?: $approval?->supplier_email_snapshot ?: '—' }}</strong></div>
      <div><span>Address</span><strong>{{ $supplier?->address ?: '—' }}</strong></div>
      <div><span>Approved Payment Method</span><strong>{{ $paymentMethod ?: '—' }}</strong></div>
      <div><span>Approved Payment Destination</span><strong>{{ $paymentInstructions ?: '—' }}</strong></div>
    </div>
    @if($group->contains(fn($item)=>filled($item->commercial_evidence_link)))<div class="supplier-documents"><strong>Supplier documents:</strong> @foreach($group as $item)@if($item->commercial_evidence_link)<a href="{{ route('pm.requests.commercial.evidence',$item) }}">{{ $item->request_code }} quotation</a>@endif @endforeach</div>@endif

    <div class="tablewrap supplier-items"><table><thead><tr><th>Line</th><th>Approved Item</th><th>Phase</th><th>Internal-Budget Allocation</th><th>Quantity</th><th>Unit Rate</th><th>Approved</th><th>Paid</th><th>Outstanding</th><th>Status</th></tr></thead><tbody>@foreach($group as $item)<tr><td>{{ $item->request_line_number }}</td><td><strong>{{ $item->item_description }}</strong>@if($item->commercial_notes)<br><small>{{ $item->commercial_notes }}</small>@endif</td><td>{{ $item->phase?->stage_label ?? '—' }}</td><td>{{ $item->internalBudgetEntry?->item_description ?? '—' }}</td><td>{{ $item->quantity ?? 1 }} {{ $item->unit ?? 'LS' }}</td><td class="amt">{{ \App\Support\Money::format($item->commercial_unit_rate ?? 0) }}</td><td class="amt">{{ \App\Support\Money::format($item->approved_payable_amount) }}</td><td class="amt">{{ \App\Support\Money::format($item->paid_to_date) }}</td><td class="amt">{{ \App\Support\Money::format($item->approved_outstanding_amount) }}</td><td><span class="status-pill payment-{{ $item->payment_status }}">{{ ucwords(str_replace('_',' ',$item->payment_status)) }}</span></td></tr>@endforeach</tbody><tfoot><tr><th colspan="6">Supplier Group Total</th><th class="amt">{{ \App\Support\Money::format($groupApproved) }}</th><th class="amt">{{ \App\Support\Money::format($groupPaid) }}</th><th class="amt">{{ \App\Support\Money::format($groupOutstanding) }}</th><th></th></tr></tfoot></table></div>

    @if($group->flatMap(fn($item)=>$item->payments)->isNotEmpty())
      <details class="group-payment-history"><summary>Payment History</summary><div class="tablewrap"><table><thead><tr><th>Item</th><th>Payment</th><th>Date</th><th>Amount</th><th>Method</th><th>Reference / Receipt</th><th>Recorded By</th><th>Evidence</th></tr></thead><tbody>@foreach($group as $item)@foreach($item->payments as $payment)<tr><td>{{ $item->item_description }}</td><td>{{ $payment->payment_code }}</td><td>{{ $payment->payment_date?->format('d M Y') }}</td><td class="amt">{{ \App\Support\Money::format($payment->amount) }}</td><td>{{ ucwords(str_replace('_',' ',$payment->payment_method)) }}@if($payment->variance_reason)<br><small>Variance: {{ $payment->variance_reason }}</small>@endif</td><td>{{ $payment->transaction_reference ?: '—' }}<br><small>{{ $payment->invoice_receipt_no ?: 'No receipt number' }}</small></td><td>{{ $payment->recordedBy?->name ?? '—' }}</td><td>@if($payment->evidence_link)<a href="{{ route('accountant.payments.evidence',$payment) }}">{{ $payment->evidence_original_name ?? 'Download' }}</a>@else — @endif</td></tr>@endforeach @endforeach</tbody></table></div></details>
    @endif

    @if(\App\Support\Money::compare($groupOutstanding,'0')>0)
      <section class="supplier-payment-controls"><h4>Pay This Supplier Group</h4><p>Record payment against the exact approved item being settled. This preserves its internal-budget allocation and audit trail.</p>
      <div class="payment-item-grid">@foreach($group->filter(fn($item)=>\App\Support\Money::compare($item->approved_outstanding_amount,'0')>0) as $item)
        @php($itemApproval=$item->latestPmApproval)
        <details class="payment-item"><summary><span>{{ $item->item_description }}</span><strong>TSh {{ \App\Support\Money::format($item->approved_outstanding_amount) }} outstanding</strong></summary>
          <form method="POST" enctype="multipart/form-data" action="{{ route('accountant.payments.store',$item) }}">@csrf<input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
            <div class="row2"><div class="field"><label for="payment_date_{{ $item->id }}">Payment Date <span class="req">*</span></label><input id="payment_date_{{ $item->id }}" type="date" name="payment_date" max="{{ now()->toDateString() }}" value="{{ now()->toDateString() }}" required></div><div class="field"><label for="amount_{{ $item->id }}">Amount Paid (TSh) <span class="req">*</span></label><input id="amount_{{ $item->id }}" type="number" name="amount" min="0.01" max="{{ $item->approved_outstanding_amount }}" step="0.01" value="{{ $item->approved_outstanding_amount }}" required></div></div>
            <div class="row2"><div class="field"><label for="payment_method_{{ $item->id }}">Actual Payment Method <span class="req">*</span></label><select id="payment_method_{{ $item->id }}" name="payment_method" required>@foreach($paymentMethods as $method)<option value="{{ $method->code }}" @selected(($itemApproval?->payment_method_snapshot ?? $item->commercial_payment_method)===$method->code)>{{ $method->label }}</option>@endforeach</select></div><div class="field"><label for="transaction_reference_{{ $item->id }}">Transaction Reference</label><input id="transaction_reference_{{ $item->id }}" name="transaction_reference"></div></div>
            <div class="row2"><div class="field"><label for="invoice_receipt_no_{{ $item->id }}">Invoice / Receipt Number</label><input id="invoice_receipt_no_{{ $item->id }}" name="invoice_receipt_no"></div><div class="field"><label for="evidence_{{ $item->id }}">Payment Evidence / Receipt</label><input id="evidence_{{ $item->id }}" type="file" name="evidence" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx"></div></div>
            <div class="field"><label for="variance_reason_{{ $item->id }}">Method Variance Reason</label><textarea id="variance_reason_{{ $item->id }}" name="variance_reason" rows="2"></textarea></div><div class="field"><label for="notes_{{ $item->id }}">Payment Notes</label><textarea id="notes_{{ $item->id }}" name="notes" rows="2"></textarea></div><button class="btn btn-primary" type="submit">Record Payment for This Item</button>
          </form>
        </details>
      @endforeach</div></section>
    @else<div class="supplier-paid-message">This supplier group is fully paid.</div>@endif
  </article>
@endforeach
</section>

<style>
.supplier-group{border:1px solid var(--line-strong);border-radius:10px;margin:18px 0;overflow:hidden;background:var(--surface)}
.supplier-group-head{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;padding:17px 18px;background:var(--surface-2);border-bottom:1px solid var(--line)}
.supplier-group-head h3{font-size:17px;margin:3px 0}.supplier-group-head p{margin:0;color:var(--ink-dim)}.supplier-kicker{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--primary-dark)}
.supplier-group-position{text-align:right;display:flex;flex-direction:column}.supplier-group-position strong{font-size:17px}.supplier-group-position span{font-size:11px;color:var(--ink-dim);margin-top:3px}
.supplier-profile-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1px;background:var(--line);border-bottom:1px solid var(--line)}.supplier-profile-grid>div{background:var(--surface);padding:12px 14px;display:flex;flex-direction:column;gap:4px}.supplier-profile-grid span{font-size:10px;color:var(--ink-dim);text-transform:uppercase;font-weight:600}.supplier-profile-grid strong{font-size:12px;overflow-wrap:anywhere}
.supplier-documents{padding:11px 14px;border-bottom:1px solid var(--line)}.supplier-documents a{margin-left:10px;color:var(--primary-dark)}.supplier-items{border:0;border-radius:0;box-shadow:none}.supplier-items small{color:var(--ink-dim)}
.group-payment-history{border-top:1px solid var(--line)}.group-payment-history>summary{padding:13px 16px;font-weight:700;cursor:pointer;background:var(--surface-2)}.group-payment-history .tablewrap{border:0;border-radius:0}
.supplier-payment-controls{padding:16px 18px;border-top:1px solid var(--line);background:#fbfcfd}.supplier-payment-controls h4{margin:0 0 4px}.supplier-payment-controls>p{margin:0 0 13px;color:var(--ink-dim);font-size:12px}.payment-item{border:1px solid var(--line);border-radius:8px;margin-top:9px;background:var(--surface)}.payment-item>summary{display:flex;justify-content:space-between;gap:12px;padding:12px 14px;cursor:pointer}.payment-item>summary strong{color:var(--primary-dark)}.payment-item form{padding:15px;border-top:1px solid var(--line)}.supplier-paid-message{padding:14px 18px;border-top:1px solid #b9e6cf;background:var(--good-soft);color:#1c7a4f;font-weight:600}
@media(max-width:700px){.supplier-group-head,.payment-item>summary{display:block}.supplier-group-position{text-align:left;margin-top:10px}}
</style>
@endsection
