@extends('layouts.app')
@section('title', $project->name.' · Edit History')
@section('content')
@include('partials.ledger.styles')
@php
  $money = \App\Support\Money::class;
  $categories = \App\Services\LedgerAnalyticsService::CATEGORIES;
  $labels = [
      'requested_at' => 'Date', 'project_phase_id' => 'Phase', 'main_category' => 'Category', 'subcategory' => 'Subcategory',
      'item_description' => 'Item', 'supplier_fundi_client' => 'Supplier', 'invoice_receipt_no' => 'Receipt no.', 'quantity' => 'Quantity',
      'unit' => 'Unit', 'unit_cost' => 'Unit cost', 'total_amount' => 'Total', 'paid' => 'Paid', 'notes' => 'Notes', 'reason' => 'Reason',
  ];
  $actions = [
      'ledger_line_updated' => 'Edited', 'ledger_line_deleted' => 'Deleted', 'ledger_line_denied' => 'Denied', 'ledger_line_restored' => 'Restored',
      'ledger_amount_confirmed' => 'Amount corrected at confirmation', 'ledger_paid_line_corrected' => 'Paid line corrected',
  ];
  $show = function (string $key, $value) use ($money, $categories, $tree, $subcategories, $units) {
      if ($value === null || $value === '') { return '—'; }
      return match ($key) {
          'requested_at' => \Carbon\CarbonImmutable::parse($value)->format('d M Y'),
          'project_phase_id' => $tree->path((int) $value) ?: '#'.$value,
          'main_category' => $categories[$value] ?? $value,
          'subcategory' => $subcategories[$value] ?? $value,
          'unit' => $units[$value] ?? $value,
          'unit_cost', 'total_amount', 'paid' => $money::format($value),
          'quantity' => rtrim(rtrim((string) $value, '0'), '.'),
          default => is_scalar($value) ? (string) $value : json_encode($value),
      };
  };
@endphp
<div class="lg-head">
  <div><h2>Edit History</h2><p>{{ $project->name }} · every change to an expense line after it was entered: who, when, and the values before and after.</p></div>
  <div class="lg-actions"><a class="btn btn-ghost" href="{{ route('ledger.expenses', $project) }}">Back to Expenditure</a></div>
</div>
<form method="GET" action="{{ route('ledger.history', $project) }}" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px" role="search">
  <input type="search" name="q" value="{{ $search }}" class="lg-search" placeholder="Line code or item" aria-label="Search the edit history" style="flex:1 1 220px;max-width:420px">
  <button class="btn btn-primary" type="submit">Search</button>
  @if($search !== '')<a class="btn btn-ghost" href="{{ route('ledger.history', $project) }}">Clear</a>@endif
</form>
<section class="lg-panel" style="padding:0;overflow:hidden">
  <div class="tablewrap" style="border:0"><table class="lg-table lg-cards-sm">
    <thead><tr><th>When</th><th>Who</th><th>Line</th><th>What</th><th>Changes</th></tr></thead>
    <tbody>
    @forelse($logs as $log)
      @php
        $before = (array) ($log->before_snapshot ?? []);
        $after = (array) ($log->after_snapshot ?? []);
        $keys = array_values(array_unique([...array_keys($before), ...array_keys($after)]));
        $changes = collect($keys)->filter(fn ($key) => $log->action !== 'ledger_line_updated' || $show($key, $before[$key] ?? null) !== $show($key, $after[$key] ?? null));
      @endphp
      <tr>
        <td data-label="When" style="white-space:nowrap">{{ $log->created_at?->format('d M Y, H:i') }}</td>
        <td data-label="Who">{{ $log->editor?->name ?? '—' }}<span class="muted">{{ str_replace('_', ' ', (string) $log->editor?->role?->name) }}</span></td>
        <td data-label="Line"><span class="code">{{ $log->document_code }}</span><span class="muted">{{ $log->item_description }}</span></td>
        <td data-label="What">{{ $actions[$log->action] ?? ucwords(str_replace('_', ' ', $log->action)) }}</td>
        <td data-label="Changes">
          @if($log->action === 'ledger_line_updated' || $log->action === 'ledger_amount_confirmed' || $log->action === 'ledger_paid_line_corrected')
            @forelse($changes as $key)<div>{{ $labels[$key] ?? ucwords(str_replace('_', ' ', $key)) }}: {{ $show($key, $before[$key] ?? null) }} → <b>{{ $show($key, $after[$key] ?? null) }}</b></div>@empty<span class="muted">No visible change</span>@endforelse
          @else
            @foreach($changes as $key)<div>{{ $labels[$key] ?? ucwords(str_replace('_', ' ', $key)) }}: {{ $show($key, $after[$key] ?? $before[$key] ?? null) }}</div>@endforeach
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="5" class="lg-empty">{{ $search !== '' ? 'Nothing found.' : 'No line has been changed yet.' }}</td></tr>
    @endforelse
    </tbody>
  </table></div>
</section>
<div style="margin-top:12px">{{ $logs->links() }}</div>
@endsection
