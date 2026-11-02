{{-- Lines this person entered today. Their own lines can be fixed or removed the same day, until Accounts confirms. --}}
@php($money = \App\Support\Money::class)
<section class="lg-panel" style="margin-top:16px">
  <h3>Entered today ({{ $today->count() }})</h3>
  <p class="hint">You can edit or delete your own lines today until Accounts confirms them. After that, ask the Project Manager.</p>
  <div class="tablewrap" style="border:0;margin-top:8px"><table class="lg-table lg-cards-sm">
    <thead><tr><th>ID</th>@if($showProject ?? false)<th>Project</th>@endif<th>Item</th><th class="amt">Amount</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse($today as $line)
      @php($state = $line->status === 'rejected' ? 'Denied' : ($line->payments->isNotEmpty() ? 'Confirmed' : 'To confirm'))
      <tr>
        <td data-label="ID" class="code">{{ $line->request_code }}<span class="muted">{{ $line->created_at?->format('H:i') }}</span></td>
        @if($showProject ?? false)<td data-label="Project">{{ $line->project?->name }}</td>@endif
        <td data-label="Item">{{ $line->item_description }}<span class="muted">{{ \App\Services\LedgerAnalyticsService::CATEGORIES[$line->main_category] ?? $line->main_category }}</span></td>
        <td data-label="Amount" class="amt">{{ $money::format($line->total_amount) }}</td>
        <td data-label="Status"><span class="lg-pill {{ ['Denied' => 'denied', 'Confirmed' => 'paid', 'To confirm' => 'unpaid'][$state] }}">{{ $state }}</span></td>
        <td class="lg-btn-cell"><div class="lg-btns">
          @can('edit-ledger-line', $line)
            @if($line->payments->isEmpty())
              <a class="lg-btn" href="{{ route('ledger.expenses.edit', [$line->project_id, $line]) }}">Edit</a>
              <form method="POST" action="{{ route('ledger.expenses.destroy', [$line->project_id, $line]) }}" onsubmit="return confirm('Delete {{ $line->request_code }}?');">@csrf @method('DELETE')<button class="lg-btn bad" type="submit">Delete</button></form>
            @endif
          @endcan
        </div></td>
      </tr>
    @empty
      <tr><td colspan="{{ ($showProject ?? false) ? 6 : 5 }}" class="lg-empty">Nothing entered yet today.</td></tr>
    @endforelse
    </tbody>
  </table></div>
</section>
