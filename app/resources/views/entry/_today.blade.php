{{-- Lines this person entered today. Their own lines can be fixed or removed the same day, until Accounts confirms. --}}
@php
  $money = \App\Support\Money::class;
  $isEntry = auth()->user()->role?->name === 'data_entry';
@endphp
<style>
  .lg-table tr.today-locked td{background:var(--surface-2);color:var(--ink-dim)}
  .today-lock{font-size:12px;color:var(--ink-dim);white-space:nowrap}
</style>
<section class="lg-panel" style="margin-top:16px">
  <h3>Entered today ({{ $today->count() }})</h3>
  @if($isEntry)<p class="hint">You can edit or delete your lines today. Shaded lines have been checked and can no longer be changed.</p>@endif
  <div class="tablewrap" style="border:0;margin-top:8px"><table class="lg-table lg-cards-sm">
    <thead><tr><th>ID</th>@if($showProject ?? false)<th>Project</th>@endif<th>Item</th><th class="amt">Amount</th>@unless($isEntry)<th>Status</th>@endunless<th></th></tr></thead>
    <tbody>
    @forelse($today as $line)
      @php($state = $line->status === 'rejected' ? 'Denied' : ($line->payments->isNotEmpty() ? 'Confirmed' : 'To confirm'))
      @php($canChange = auth()->user()->can('edit-ledger-line', $line) && $line->payments->isEmpty())
      <tr @class(['today-locked' => $isEntry && ! $canChange])>
        <td data-label="ID" class="code">{{ $line->request_code }}<span class="muted">{{ $line->created_at?->format('H:i') }}</span></td>
        @if($showProject ?? false)<td data-label="Project">{{ $line->project?->name }}</td>@endif
        <td data-label="Item">{{ $line->item_description }}<span class="muted">@if($line->supplier_fundi_client){{ $line->supplier_fundi_client }} · @endif{{ $line->project?->is_company_overheads ? $line->phase?->name : (\App\Services\LedgerAnalyticsService::CATEGORIES[$line->main_category] ?? $line->main_category) }}</span></td>
        <td data-label="Amount" class="amt">{{ $money::format($line->total_amount) }}</td>
        @unless($isEntry)<td data-label="Status"><span class="lg-pill {{ ['Denied' => 'denied', 'Confirmed' => 'paid', 'To confirm' => 'unpaid'][$state] }}">{{ $state }}</span></td>@endunless
        <td class="lg-btn-cell"><div class="lg-btns">
          @if($canChange)
            <a class="lg-btn" href="{{ route('ledger.expenses.edit', [$line->project_id, $line]) }}">Edit</a>
            <form method="POST" action="{{ route('ledger.expenses.destroy', [$line->project_id, $line]) }}" onsubmit="return confirm('Delete {{ $line->request_code }}?');">@csrf @method('DELETE')<button class="lg-btn bad" type="submit">Delete</button></form>
          @elseif($isEntry)
            <span class="today-lock">🔒 Can't be changed</span>
          @endif
        </div></td>
      </tr>
    @empty
      <tr><td colspan="{{ (($showProject ?? false) ? 6 : 5) - ($isEntry ? 1 : 0) }}" class="lg-empty">Nothing entered yet today.</td></tr>
    @endforelse
    </tbody>
  </table></div>
</section>
