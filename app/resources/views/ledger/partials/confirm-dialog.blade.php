{{-- Confirm-payment dialog for buttons with data-confirm-url (Accounts / Director). Needs $methods. --}}
<dialog class="lg-dialog" id="confirmDialog">
  <form method="POST" id="confirmForm">@csrf
    <h3>Confirm payment</h3>
    <p class="form-context" id="confirmWhat" style="margin:0"></p>
    <div class="field" id="totalField"><label for="confirm_total_amount">Line amount (TSh) — correct it if the final price differs</label><input type="number" step="0.01" min="0" id="confirm_total_amount" name="total_amount"></div>
    <div class="field"><label for="confirm_amount">Amount paid now (TSh) <span class="req">*</span></label><input type="number" step="0.01" min="0.01" id="confirm_amount" name="amount" required></div>
    <div class="row2"><div class="field"><label for="confirm_payment_date">Payment date <span class="req">*</span></label><input type="date" id="confirm_payment_date" name="payment_date" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" required></div>
    <div class="field"><label for="confirm_payment_method">Method</label><select id="confirm_payment_method" name="payment_method"><option value="">Not recorded</option>@foreach($methods as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select></div></div>
    <div class="row2"><div class="field"><label for="confirm_receipt">Receipt no. (optional)</label><input id="confirm_receipt" name="invoice_receipt_no" maxlength="255"></div>
    <div class="field"><label for="confirm_reference">Reference (optional)</label><input id="confirm_reference" name="transaction_reference" maxlength="255" placeholder="Bank / mobile money ref."></div></div>
    <div style="display:flex;gap:8px;justify-content:flex-end"><button class="btn btn-ghost" type="button" onclick="this.closest('dialog').close()">Cancel</button><button class="btn btn-primary" type="submit">Confirm Payment</button></div>
  </form>
</dialog>
<script>
(() => {
  const dialog = document.getElementById('confirmDialog'), form = document.getElementById('confirmForm');
  const total = document.getElementById('confirm_total_amount'), amount = document.getElementById('confirm_amount');
  document.querySelectorAll('[data-confirm-url]').forEach(button => button.addEventListener('click', () => {
    form.action = button.dataset.confirmUrl;
    document.getElementById('confirmWhat').textContent = button.dataset.code + ' · ' + button.dataset.item;
    const unpaid = parseFloat(button.dataset.paid) === 0;
    document.getElementById('totalField').hidden = !unpaid;
    total.value = unpaid ? button.dataset.total : '';
    amount.value = button.dataset.outstanding;
    dialog.showModal();
  }));
  total.addEventListener('input', () => { amount.value = total.value; });
})();
</script>
