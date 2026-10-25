@php
  $money = \App\Support\Money::class;
  $metrics = [
      'invoiced_period' => 'Invoiced to clients',
      'received_period' => 'Client money received',
      'expenses_period' => 'Money paid out',
      'net' => 'Net cash flow (received − paid)',
  ];
  $value = fn ($set, $key) => $key === 'net' ? $money::difference($set['received_period'], $set['expenses_period']) : $set[$key];
@endphp
<section class="panel form-section period-review">
  <h3>Period Review</h3>
  <p class="form-context">{{ $range['label'] }}, compared with {{ $previousRange['label'] }}.</p>
  <div class="tablewrap"><table style="min-width:620px"><thead><tr><th>Measure</th><th class="amt">This period</th><th class="amt">Previous period</th><th class="amt">Change</th></tr></thead><tbody>
  @foreach ($metrics as $key => $label)
    @php($now = $value($current, $key)) @php($before = $value($previous, $key)) @php($diff = $money::difference($now, $before))
    @php($better = $key === 'expenses_period' ? $money::compare($diff, '0') < 0 : $money::compare($diff, '0') > 0)
    <tr>
      <td>{{ $label }}</td>
      <td class="amt">TSh {{ $money::format($now) }}</td>
      <td class="amt">TSh {{ $money::format($before) }}</td>
      <td class="amt">@if($money::compare($diff, '0') === 0)<span class="change-flat">No change</span>@else<span class="{{ $better ? 'change-good' : 'change-bad' }}">{{ $money::compare($diff, '0') > 0 ? '▲' : '▼' }} TSh {{ $money::format(ltrim($diff, '-')) }}@if($money::compare($before, '0') !== 0) ({{ $money::format(ltrim($money::percentage($diff, ltrim($before, '-')), '-')) }}%)@endif</span>@endif</td>
    </tr>
  @endforeach
  </tbody></table></div>
</section>
<style>.change-good{color:#1c7a4f;font-weight:600}.change-bad{color:#b42318;font-weight:600}.change-flat{color:var(--ink-dim)}</style>
