@extends('layouts.app')
@section('title', 'My Entries · Buildiwick')
@section('content')
@include('partials.ledger.styles')
@php
  $money = \App\Support\Money::class;
  $sumOf = fn ($lines) => \App\Services\LedgerAnalyticsService::money((int) $lines->where('status', '!=', 'rejected')->sum(fn ($line) => \App\Services\LedgerAnalyticsService::cents($line->total_amount)));
@endphp
<div class="lg-head">
  <div><h2>My Entries</h2><p>The projects you entered on. Choose one to see the lines.</p></div>
</div>
@include('entry._period', ['route' => fn (array $q) => route('entry.today', $q)])
@if($projects->count() > 6)<div class="entry-search-bar"><input type="search" id="entrySearch" class="lg-search" placeholder="Search project or place…" aria-label="Search project" autocomplete="off" enterkeyhint="search"></div>@endif
<p class="hint" style="margin:0 0 10px">In total: <b>{{ $lines->count() }}</b> line{{ $lines->count() === 1 ? '' : 's' }} · TSh {{ $money::format($sumOf($lines)) }}</p>
@if($projects->isEmpty())
  <section class="lg-panel"><p class="lg-empty" style="margin:0">{{ $period['key'] === 'today' ? 'Nothing entered yet today.' : 'Nothing entered in this period.' }}</p></section>
@else
  <div class="entry-projects" id="entryProjects">
    @foreach($projects as $project)
      @php($mine = $byProject->get($project->id, collect()))
      <a class="entry-project has" href="{{ route('entry.today.project', [$project] + $period['query']) }}" data-search="{{ strtolower($project->name.' '.$project->project_code.' '.$project->location) }}">
        <b>{{ $project->name }}</b><span>{{ $project->project_code }}</span>
        <em>{{ $mine->count() }} line{{ $mine->count() === 1 ? '' : 's' }} · TSh {{ $money::format($sumOf($mine)) }}</em>
      </a>
    @endforeach
  </div>
  <p class="hint" id="entryNone" hidden>No project matches.</p>
@endif
<style>
  .entry-projects{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px}
  .entry-project{display:flex;flex-direction:column;gap:3px;padding:14px 16px;border:1px solid var(--line);border-radius:12px;background:var(--surface);text-decoration:none;color:var(--ink)}
  .entry-project:hover{border-color:var(--primary);background:var(--primary-soft)}
  .entry-project[hidden]{display:none}
  .entry-search-bar{position:sticky;top:0;z-index:5;background:var(--bg);padding:6px 0 10px;margin-bottom:4px}
  .entry-search-bar .lg-search{width:100%;max-width:420px;font-size:16px;padding:11px 14px}
  @media(max-width:860px){.entry-search-bar{top:50px;padding-top:10px}}
  @media(max-width:700px){.entry-projects{gap:8px}.entry-project{padding:11px 14px}}
  .entry-project b{font-size:14px}.entry-project span{font-size:12px;color:var(--ink-dim)}
  .entry-project em{font-style:normal;font-size:12.5px;color:var(--ink-dim);margin-top:4px}
  .entry-project.has{border-left:4px solid var(--primary)}.entry-project.has em{color:var(--primary-dark);font-weight:600}
</style>
<script>
(() => {
  const search = document.getElementById('entrySearch');
  if (!search) return;
  const cards = [...document.querySelectorAll('#entryProjects [data-search]')];
  search.addEventListener('input', () => {
    // Every word typed must appear somewhere (name, code or location), in any order.
    const words = search.value.trim().toLowerCase().split(/\s+/).filter(Boolean); let shown = 0;
    cards.forEach(c => { c.hidden = !words.every(w => c.dataset.search.includes(w)); shown += c.hidden ? 0 : 1; });
    document.getElementById('entryNone').hidden = shown > 0;
  });
})();
</script>
@endsection
