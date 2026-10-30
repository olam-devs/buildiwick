{{-- $segments: [['label','value','color']], $center: big text, $centerSub: small text --}}
@php
  $total = collect($segments)->sum(fn ($s) => (float) $s['value']);
  $radius = 54; $circumference = 2 * M_PI * $radius; $offset = 0;
@endphp
<svg viewBox="0 0 140 140" width="{{ $size ?? 150 }}" height="{{ $size ?? 150 }}" role="img" aria-label="Share by category">
  <circle cx="70" cy="70" r="{{ $radius }}" fill="none" stroke="#EEF1F4" stroke-width="16"/>
  @if($total > 0)
    @foreach($segments as $segment)
      @php($length = $circumference * ((float) $segment['value'] / $total))
      @if($length > 0)
        <circle cx="70" cy="70" r="{{ $radius }}" fill="none" stroke="{{ $segment['color'] }}" stroke-width="16"
          stroke-dasharray="{{ round($length, 3) }} {{ round($circumference - $length, 3) }}" stroke-dashoffset="{{ round(-$offset, 3) }}"
          transform="rotate(-90 70 70)"><title>{{ $segment['label'] }}</title></circle>
        @php($offset += $length)
      @endif
    @endforeach
  @endif
  <text x="70" y="66" text-anchor="middle" font-size="15" font-weight="700" fill="#1F272E">{{ $center ?? '' }}</text>
  <text x="70" y="84" text-anchor="middle" font-size="9" fill="#8D99A6">{{ $centerSub ?? '' }}</text>
</svg>
