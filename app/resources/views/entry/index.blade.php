@extends('layouts.app')
@section('title', 'New Entry · Buildiwick')
@section('content')
@include('partials.ledger.styles')
<div class="lg-head">
  <div><h2>New Entry</h2><p>Choose the project, then enter the expenses.</p></div>
</div>
@if($projects->count() > 6)<div class="entry-search-bar"><input type="search" id="entrySearch" class="lg-search" placeholder="Search project or place…" aria-label="Search project" autocomplete="off" enterkeyhint="search"></div>@endif

@if($projects->isEmpty())
  <div class="flash error">You have no projects yet. Ask the Director to give you projects in User Management.</div>
@else
  <div class="entry-projects" id="entryProjects">
    @foreach($projects as $project)
      <a class="entry-project" href="{{ route('entry.form', $project) }}" data-search="{{ strtolower($project->name.' '.$project->project_code.' '.$project->location) }}">
        <b>{{ $project->name }}</b><span>{{ $project->project_code }}@if($project->location) · {{ $project->location }}@endif</span>
        @if($drafts->has($project->id))<em class="entry-draft">Unfinished entry · {{ $drafts[$project->id] }} item{{ $drafts[$project->id] == 1 ? '' : 's' }}</em>@endif
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
  .entry-project[hidden]{display:none}
  .entry-search-bar{position:sticky;top:0;z-index:5;background:var(--bg);padding:6px 0 10px;margin-bottom:4px}
  .entry-search-bar .lg-search{width:100%;max-width:420px;font-size:16px;padding:11px 14px}
  @media(max-width:860px){.entry-search-bar{top:50px;padding-top:10px}}
  @media(max-width:700px){.entry-projects{gap:8px}.entry-project{padding:11px 14px}}
  .entry-project b{font-size:14px}.entry-project span{font-size:12px;color:var(--ink-dim)}
  .entry-draft{font-style:normal;font-size:12.5px;font-weight:600;color:var(--primary-dark);margin-top:4px}
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
