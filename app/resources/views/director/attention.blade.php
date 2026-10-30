@extends('layouts.app')
@section('title', 'Needs Attention · Buildiwick')
@section('content')
@include('partials.ledger.styles')
@php
  $counts = collect($attention)->flatMap(fn ($row) => collect($row['warnings'])->pluck('rule'))->countBy();
  $types = ['to_confirm_days' => 'Awaiting confirmation', 'budget_used_pct' => 'Budget', 'no_activity_days' => 'No recent expense', 'phase_late_days' => 'Late phase'];
@endphp
<div class="lg-head">
  <div><h2>Needs Attention</h2><p>{{ $attention->count() }} projects have warnings from your warning rules. Underlined items open the lines to act on; the rest are for information.</p></div>
  <div class="lg-actions"><input type="search" id="attSearch" class="lg-search" placeholder="Search project…"><a class="btn btn-ghost" href="{{ route('director.warning-rules.index') }}">Warning rules</a></div>
</div>
<div class="lg-chips" id="attTypes">
  <button type="button" class="lg-chip on" data-type="">All</button>
  @foreach($types as $code => $label)@if($counts[$code] ?? 0)<button type="button" class="lg-chip" data-type="{{ $code }}">{{ $label }} ({{ $counts[$code] }})</button>@endif @endforeach
</div>
<section class="lg-panel" id="attList">
  @forelse($attention as $row)
    <div data-search="{{ strtolower($row['project']->name.' '.$row['project']->project_code) }}" data-types="{{ collect($row['warnings'])->pluck('rule')->unique()->join(' ') }}">
      @include('director.partials.attention-row', ['row' => $row])
    </div>
  @empty
    <p class="hint">No warnings. Every project is within your limits.</p>
  @endforelse
  <p class="hint" id="attNone" hidden>No project matches.</p>
</section>
<script>
(() => {
  const items = [...document.querySelectorAll('#attList [data-search]')], search = document.getElementById('attSearch');
  let type = '';
  const apply = () => {
    const q = search.value.trim().toLowerCase(); let shown = 0;
    items.forEach(i => { i.hidden = (q && !i.dataset.search.includes(q)) || (type && !i.dataset.types.split(' ').includes(type)); shown += i.hidden ? 0 : 1; });
    document.getElementById('attNone').hidden = shown > 0 || !items.length;
  };
  search.addEventListener('input', apply);
  document.querySelectorAll('#attTypes [data-type]').forEach(b => b.addEventListener('click', () => {
    document.querySelectorAll('#attTypes [data-type]').forEach(x => x.classList.toggle('on', x === b)); type = b.dataset.type; apply();
  }));
})();
</script>
@endsection
