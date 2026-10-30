{{-- $months: from LedgerAnalyticsService::monthly() — stacked columns by category --}}
@php
  $colors = \App\Services\LedgerAnalyticsService::CATEGORY_COLORS;
  $max = max(1, collect($months)->max(fn ($m) => (float) $m['total']));
  $count = max(1, count($months));
  $width = 640; $height = 200; $pad = 28; $bottom = 22;
  $slot = ($width - $pad) / $count; $barWidth = max(6, min(34, $slot * 0.62));
  $short = function (float $value): string {
      return $value >= 1e9 ? round($value / 1e9, 1).'B' : ($value >= 1e6 ? round($value / 1e6, 1).'M' : ($value >= 1e3 ? round($value / 1e3).'K' : (string) round($value)));
  };
@endphp
<svg viewBox="0 0 {{ $width }} {{ $height }}" width="100%" preserveAspectRatio="none" role="img" aria-label="Monthly spend" style="max-height:230px">
  @foreach([0.25, 0.5, 0.75, 1] as $step)
    @php($y = ($height - $bottom) - ($height - $bottom - 10) * $step)
    <line x1="{{ $pad }}" x2="{{ $width }}" y1="{{ $y }}" y2="{{ $y }}" stroke="#EEF1F4"/>
    <text x="0" y="{{ $y + 3 }}" font-size="9" fill="#8D99A6">{{ $short($max * $step) }}</text>
  @endforeach
  @foreach($months as $index => $month)
    @php($x = $pad + $slot * $index + ($slot - $barWidth) / 2) @php($base = $height - $bottom)
    @foreach($month['categories'] as $code => $amount)
      @php($h = ($height - $bottom - 10) * ((float) $amount / $max))
      @if($h > 0)
        <rect x="{{ round($x, 2) }}" y="{{ round($base - $h, 2) }}" width="{{ round($barWidth, 2) }}" height="{{ round($h, 2) }}" fill="{{ $colors[$code] ?? '#999' }}" rx="2"><title>{{ $month['label'] }} · {{ \App\Services\LedgerAnalyticsService::CATEGORIES[$code] ?? $code }}: TSh {{ \App\Support\Money::format($amount) }}</title></rect>
        @php($base -= $h)
      @endif
    @endforeach
    <text x="{{ round($x + $barWidth / 2, 2) }}" y="{{ $height - 6 }}" text-anchor="middle" font-size="9" fill="#8D99A6">{{ $month['label'] }}</text>
  @endforeach
</svg>
