@extends('layouts.app')
@section('title', 'New Tabs from the Google Sheet · Buildiwick')
@section('content')
@include('partials.ledger.styles')
<div class="lg-head">
  <div><h2>New Tabs from the Google Sheet</h2><p>Projects Data Entry started in the entry sheet by duplicating the "+ New project" tab. She can keep typing in the tab while it waits; once you create the project, its lines go into her Record Expense draft like every other project.</p></div>
  <div class="lg-actions"><a class="btn btn-ghost" href="{{ route('admin.projects.index') }}">All projects</a></div>
</div>

<section class="lg-panel" style="padding:0;overflow:hidden;margin-bottom:18px">
  <div class="tablewrap" style="border:0"><table class="lg-table lg-cards-sm">
    <thead><tr><th>Tab</th><th class="amt">Lines typed</th><th>Added</th><th></th></tr></thead>
    <tbody>
    @forelse($waiting as $tab)
      <tr>
        <td data-label="Tab"><b>{{ $tab->title }}</b>@unless($tab->layout_ok)<span class="muted" style="display:block;color:var(--danger-ink)">Not in the Buildiwick layout: its lines cannot be read. Ask her to copy them into a duplicate of the "+ New project" tab.</span>@endunless</td>
        <td data-label="Lines typed" class="amt">{{ $tab->line_count }}</td>
        <td data-label="Added" style="white-space:nowrap">{{ $tab->created_at?->format('d M Y, H:i') }}</td>
        <td><div class="lg-row-actions" style="justify-content:flex-end">
          @if($tab->layout_ok)<a class="btn btn-primary" href="{{ route('admin.projects.create', ['sheet_tab' => $tab->id]) }}">Create project</a>@endif
          <form method="POST" action="{{ route('admin.sheet-tabs.reject', $tab) }}" onsubmit="return confirm('Mark this tab as not a project? Its lines will not be read.')">@csrf<button class="btn btn-ghost" type="submit">Not a project</button></form>
        </div></td>
      </tr>
    @empty
      <tr><td colspan="4" class="lg-empty">No new tabs are waiting. The sheet is checked every 5 minutes.</td></tr>
    @endforelse
    </tbody>
  </table></div>
</section>

@if($decided->isNotEmpty())
<h3 style="font-size:14px;margin:0 0 8px">Decided</h3>
<section class="lg-panel" style="padding:0;overflow:hidden">
  <div class="tablewrap" style="border:0"><table class="lg-table lg-cards-sm">
    <thead><tr><th>Tab</th><th>Decision</th><th>By</th><th></th></tr></thead>
    <tbody>
    @foreach($decided as $tab)
      <tr>
        <td data-label="Tab">{{ $tab->title }}</td>
        <td data-label="Decision">
          @if($tab->status === 'approved')Project @if($tab->project)<a class="lg-link" href="{{ route('ledger.overview', $tab->project) }}">{{ $tab->project->project_code }}</a>@endif
          @else Not a project @endif
        </td>
        <td data-label="By">{{ $tab->decider?->name ?? '—' }}<span class="muted">{{ $tab->decided_at?->format('d M Y, H:i') }}</span></td>
        <td>@if($tab->status === 'rejected')<form method="POST" action="{{ route('admin.sheet-tabs.reopen', $tab) }}">@csrf<button class="btn btn-ghost" type="submit">Reopen</button></form>@endif</td>
      </tr>
    @endforeach
    </tbody>
  </table></div>
</section>
@endif
@endsection
