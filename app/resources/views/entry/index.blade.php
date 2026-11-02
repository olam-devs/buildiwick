@extends('layouts.app')
@section('title', 'New Entry · Buildiwick')
@section('content')
@include('partials.ledger.styles')
<div class="lg-head">
  <div><h2>New Entry</h2><p>Choose the project, then enter the expenses. Accounts confirms them.</p></div>
  @if($projects->count() > 6)<div class="lg-actions"><input type="search" id="entrySearch" class="lg-search" placeholder="Search project…" autofocus></div>@endif
</div>

@if($projects->isEmpty())
  <div class="flash error">You have no projects yet. Ask the Director to give you projects in User Management.</div>
@else
  <div class="entry-projects" id="entryProjects">
    @foreach($projects as $project)
      <a class="entry-project" href="{{ route('entry.form', $project) }}" data-search="{{ strtolower($project->name.' '.$project->project_code.' '.$project->location) }}">
        <b>{{ $project->name }}</b><span>{{ $project->project_code }}@if($project->location) · {{ $project->location }}@endif</span>
      </a>
    @endforeach
  </div>
  <p class="hint" id="entryNone" hidden>No project matches.</p>
@endif

@include('entry._today', ['today' => $today, 'showProject' => true])

<style>
  .entry-projects{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px}
  .entry-project{display:flex;flex-direction:column;gap:3px;padding:14px 16px;border:1px solid var(--line);border-radius:12px;background:var(--surface);text-decoration:none;color:var(--ink)}
  .entry-project:hover{border-color:var(--primary);background:var(--primary-soft)}
  .entry-project b{font-size:14px}.entry-project span{font-size:12px;color:var(--ink-dim)}
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
