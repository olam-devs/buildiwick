{{-- Period filter for My Entries: quick choices plus picked dates (the phone's own calendar). --}}
@php($chips = ['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This week', 'month' => 'This month'])
<div class="period-bar">
  <div class="period-chips">
    @foreach($chips as $key => $text)
      <a @class(['period-chip', 'on' => $period['key'] === $key]) href="{{ $route($key === 'today' ? [] : ['period' => $key]) }}">{{ $text }}</a>
    @endforeach
    <button type="button" @class(['period-chip', 'on' => $period['key'] === 'custom']) id="periodPick" aria-expanded="{{ $period['key'] === 'custom' ? 'true' : 'false' }}">📅 Pick dates</button>
  </div>
  <form method="GET" action="{{ $route([]) }}" class="period-custom" id="periodCustom" @unless($period['key'] === 'custom') hidden @endunless>
    <input type="hidden" name="period" value="custom">
    <label>From <input type="date" name="from" value="{{ $period['from']->toDateString() }}" max="{{ today()->toDateString() }}" required></label>
    <label>To <input type="date" name="to" value="{{ $period['to']->toDateString() }}" max="{{ today()->toDateString() }}" required></label>
    <button class="btn btn-primary" type="submit">Show</button>
  </form>
  <p class="period-label">Showing: <b>{{ $period['label'] }}</b></p>
</div>
<style>
  .period-bar{margin:0 0 12px}
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
