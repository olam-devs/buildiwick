{{-- Period filter for My Entries: quick choices plus picked dates (the phone's own calendar). --}}
@php
  $chips = ['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This week', 'month' => 'This month', 'year' => 'Last 12 months'];
  $keep = $period['keep'] ?? [];
  $periodOnly = array_diff_key($period['query'], ['by' => 1, 'q' => 1]);
@endphp
<div class="period-bar">
  {{-- Search the item (and supplier, receipt no., code) within the period below. --}}
  <form method="GET" action="{{ $route([]) }}" class="period-search" role="search">
    @foreach($periodOnly + array_diff_key($keep, ['q' => 1]) as $name => $value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
    <input type="search" name="q" value="{{ $period['q'] ?? '' }}" placeholder="Search item, supplier, receipt no. or code" aria-label="Search entries">
    <button class="btn btn-primary" type="submit">Search</button>
    @if(($period['q'] ?? '') !== '')<a class="btn btn-ghost" href="{{ $route($periodOnly + array_diff_key($keep, ['q' => 1])) }}">Clear</a>@endif
  </form>
  <div class="period-by" role="group" aria-label="Which date to filter by">
    <span>Dates by:</span>
    <a @class(['period-chip', 'on' => ($period['by'] ?? 'entered') === 'entered']) href="{{ $route($periodOnly + array_diff_key($keep, ['by' => 1])) }}">Date entered</a>
    <a @class(['period-chip', 'on' => ($period['by'] ?? 'entered') === 'expense']) href="{{ $route($periodOnly + ['by' => 'expense'] + $keep) }}">Expense date</a>
  </div>
  <div class="period-chips">
    @foreach($chips as $key => $text)
      <a @class(['period-chip', 'on' => $period['key'] === $key]) href="{{ $route(($key === 'today' ? [] : ['period' => $key]) + $keep) }}">{{ $text }}</a>
    @endforeach
    <button type="button" @class(['period-chip', 'on' => $period['key'] === 'custom']) id="periodPick" aria-expanded="{{ $period['key'] === 'custom' ? 'true' : 'false' }}">📅 Pick dates</button>
  </div>
  <form method="GET" action="{{ $route([]) }}" class="period-custom" id="periodCustom" @unless($period['key'] === 'custom') hidden @endunless>
    <input type="hidden" name="period" value="custom">
    @foreach($keep as $name => $value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
    <label>From <input type="date" name="from" value="{{ $period['from']->toDateString() }}" max="{{ today()->toDateString() }}" required></label>
    <label>To <input type="date" name="to" value="{{ $period['to']->toDateString() }}" max="{{ today()->toDateString() }}" required></label>
    <button class="btn btn-primary" type="submit">Show</button>
  </form>
  <p class="period-label">Showing: <b>{{ $period['label'] }}</b>@if(($period['q'] ?? '') !== '') · search “{{ $period['q'] }}”@endif</p>
</div>
<style>
  .period-bar{margin:0 0 12px}
  .period-search{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
  .period-search input[type=search]{flex:1 1 220px;font:inherit;font-size:16px;padding:8px 12px;border:1px solid var(--line-strong);border-radius:8px;background:var(--surface);color:var(--ink)}
  .period-by{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-bottom:8px;font-size:13px;color:var(--ink-soft)}
  .period-chips{display:flex;flex-wrap:wrap;gap:6px}
  .period-chip{display:inline-flex;align-items:center;padding:8px 13px;border:1px solid var(--line-strong);border-radius:999px;background:var(--surface);color:var(--ink);font:inherit;font-size:13px;font-weight:600;text-decoration:none;cursor:pointer}
  .period-chip.on{background:var(--primary);border-color:var(--primary);color:#fff}
  .period-custom{display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;margin-top:10px;padding:12px;border:1px solid var(--line);border-radius:12px;background:var(--surface)}
  .period-custom[hidden]{display:none}
  .period-custom label{display:flex;flex-direction:column;gap:4px;font-size:12.5px;font-weight:600;color:var(--ink-soft)}
  .period-custom input{font:inherit;font-size:16px;padding:8px 10px;border:1px solid var(--line-strong);border-radius:8px;background:var(--surface);color:var(--ink)}
  .period-label{font-size:12.5px;color:var(--ink-dim);margin:8px 0 0}
  @media(max-width:520px){.period-custom label{flex:1 1 40%}.period-custom input{width:100%}.period-custom .btn{flex:1 1 100%}}
</style>
<script>
(() => {
  const pick = document.getElementById('periodPick'), box = document.getElementById('periodCustom');
  pick.addEventListener('click', () => { box.hidden = !box.hidden; pick.setAttribute('aria-expanded', box.hidden ? 'false' : 'true'); if (!box.hidden) box.querySelector('input[name=from]').focus(); });
  // "To" can never be before "From".
  const from = box.querySelector('[name=from]'), to = box.querySelector('[name=to]');
  from.addEventListener('change', () => { to.min = from.value; if (to.value && to.value < from.value) to.value = from.value; });
  to.min = from.value;
})();
</script>
