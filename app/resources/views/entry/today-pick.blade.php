@extends('layouts.app')
@section('title', 'Entered Today · Buildiwick')
@section('content')
@include('partials.ledger.styles')
@php
  $money = \App\Support\Money::class;
  $sumOf = fn ($lines) => \App\Services\LedgerAnalyticsService::money((int) $lines->where('status', '!=', 'rejected')->sum(fn ($line) => \App\Services\LedgerAnalyticsService::cents($line->total_amount)));
@endphp
<div class="lg-head">
  <div><h2>Entered Today</h2><p>{{ now()->format('l, d M Y') }} · choose the project to see what you entered on it today.</p></div>
  @if($projects->count() > 6)<div class="lg-actions"><input type="search" id="entrySearch" class="lg-search" placeholder="Search project…"></div>@endif
</div>
<p class="hint" style="margin:0 0 10px">Today in total: <b>{{ $lines->count() }}</b> line{{ $lines->count() === 1 ? '' : 's' }} · TSh {{ $money::format($sumOf($lines)) }}</p>
@if($projects->isEmpty())
  <div class="flash error">You have no projects yet.</div>
@else
  <div class="entry-projects" id="entryProjects">
    @foreach($projects as $project)
      @php($mine = $byProject->get($project->id, collect()))
      <a @class(['entry-project', 'has' => $mine->isNotEmpty()]) href="{{ route('entry.today.project', $project) }}" data-search="{{ strtolower($project->name.' '.$project->project_code.' '.$project->location) }}">
        <b>{{ $project->name }}</b><span>{{ $project->project_code }}</span>
        <em>@if($mine->isNotEmpty()){{ $mine->count() }} line{{ $mine->count() === 1 ? '' : 's' }} · TSh {{ $money::format($sumOf($mine)) }}@else Nothing today @endif</em>
      </a>
    @endforeach
  </div>
  <p class="hint" id="entryNone" hidden>No project matches.</p>
@endif
<style>
  .entry-projects{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px}
  .entry-project{display:flex;flex-direction:column;gap:3px;padding:14px 16px;border:1px solid var(--line);border-radius:12px;background:var(--surface);text-decoration:none;color:var(--ink)}
  .entry-project:hover{border-color:var(--primary);background:var(--primary-soft)}
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
    const q = search.value.trim().toLowerCase(); let shown = 0;
    cards.forEach(c => { c.hidden = q !== '' && !c.dataset.search.includes(q); shown += c.hidden ? 0 : 1; });
    document.getElementById('entryNone').hidden = shown > 0;
  });
})();
</script>
@endsection
