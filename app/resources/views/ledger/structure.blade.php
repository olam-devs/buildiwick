@extends('layouts.app')
@section('title', $project->name.' · Phases & Budget')
@section('content')
@include('partials.ledger.styles')
@php
  $money = \App\Support\Money::class;
  $categories = \App\Services\LedgerAnalyticsService::CATEGORIES;
  $colors = \App\Services\LedgerAnalyticsService::CATEGORY_COLORS;
  $short = ['materials' => 'Materials', 'labour_subcontracts' => 'Labour', 'logistics_equipment' => 'Logistics', 'preliminaries_overheads' => 'Prelims & Overheads'];
  $canEdit = auth()->user()->can('manage-project-structure', $project);
  $showSpend = auth()->user()->can('view-analytics', $project);
  $totalBudget = $money::sum($rows->where('depth', 1)->pluck('budget'));
  $totalRecorded = $money::sum($rows->where('depth', 1)->pluck('recorded'));
@endphp
<div class="lg-head">
  <div><h2>Phases &amp; Budget</h2><p>{{ $project->name }} · phases with optional subphases and sub-subphases. Expenses and category budgets go on the lowest level: a phase without subphases takes them itself; a phase with subphases shows their totals. Budgets are optional, so recording can start as soon as a phase exists.@unless($canEdit) Only the Director can change them.@endunless</p></div>
  @if($canEdit)<form method="POST" action="{{ route('ledger.phases.store', $project) }}" class="lg-actions">@csrf<input name="phase_name" maxlength="150" placeholder="New phase name" required style="padding:8px 10px;border:1px solid var(--line);border-radius:8px;min-width:220px"><button class="btn btn-primary" type="submit">+ Add Phase</button></form>@endif
</div>
@if ($errors->any())<div class="flash error"><ul style="margin:0;padding-left:20px;">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

@include('ledger.partials.team-kpis')
@if($showSpend)
<div class="lg-cards">
  <div class="lg-card accent"><div class="k">Total budget</div><div class="v">TSh {{ $money::format($totalBudget) }}</div></div>
  <div class="lg-card"><div class="k">Recorded spend</div><div class="v">TSh {{ $money::format($totalRecorded) }}</div></div>
  @php($used = (float) $totalBudget > 0 ? $money::percentage($totalRecorded, $totalBudget) : null)
  <div class="lg-card {{ $used !== null && (float) $used > 100 ? 'danger' : '' }}"><div class="k">Budget used</div><div class="v">{{ $used !== null ? $money::format($used).'%' : '—' }}</div>@if($used !== null)<div class="lg-meter"><span class="{{ (float) $used > 100 ? 'over' : ((float) $used >= 85 ? 'near' : '') }}" style="width:{{ min(100, (float) $used) }}%"></span></div>@endif</div>
</div>
@endif

<form method="POST" action="{{ route('ledger.budget.save', $project) }}" id="budgetForm">@csrf</form>
<div class="lg-scroll" style="max-height:none">
  <table class="lg-table lg-tree">
    <thead><tr><th style="min-width:200px">Phase</th>@foreach($categories as $code => $label)<th class="amt"><span class="lg-cat" style="justify-content:flex-end" title="{{ $label }}"><i style="background:{{ $colors[$code] }}"></i>{{ $short[$code] }}</span></th>@endforeach<th style="min-width:170px">Total (incl. subphases)</th>@if($canEdit)<th></th>@endif</tr></thead>
    <tbody>
    @forelse($rows as $row)
      @php($phase = $row['phase'])
      <tr class="{{ $row['depth'] === 1 ? 'top' : '' }}">
        <td class="phase d{{ $row['depth'] }}">
          @if($canEdit)<input form="rename-{{ $phase->id }}" name="phase_name" value="{{ $row['label'] }}" maxlength="150" style="border:1px solid transparent;background:transparent;padding:3px 5px;border-radius:6px;font:inherit;font-weight:inherit;width:100%" onfocus="this.style.borderColor='var(--line)'" onchange="this.form.submit()">@else{{ $row['label'] }}@endif
          <span class="muted">{{ $row['depth'] === 1 ? 'Phase' : ($row['depth'] === 2 ? 'Subphase' : 'Sub-subphase') }}@if($showSpend) · {{ $row['count'] }} lines @endif</span>
        </td>
        @foreach(array_keys($categories) as $code)
          <td class="amt">@php($cat = $row['categories'][$code])@if(count($row['ids']) === 1 || $cat['own_budget'] !== null)@if($canEdit)<input form="budgetForm" type="number" step="0.01" min="0" name="budget[{{ $phase->id }}][{{ $code }}]" value="{{ $cat['own_budget'] !== null ? (float) $cat['own_budget'] : '' }}" placeholder="—" style="width:104px;text-align:right;padding:5px 7px;border:1px solid var(--line);border-radius:6px">@else{{ $cat['own_budget'] !== null ? $money::format($cat['own_budget']) : '—' }}@endif
            @if(count($row['ids']) > 1)<span class="muted" style="color:#9a6400">set before subphases — clear it and budget the subphases</span>@endif
            @else<span title="Total of its subphases">{{ (float) $cat['budget'] ? $money::format($cat['budget']) : '—' }}</span>@endif
            @if($showSpend && (float) $row['categories'][$code]['recorded'])<span class="muted">spent {{ $money::format($row['categories'][$code]['recorded']) }}</span>@endif</td>
        @endforeach
        <td><b>{{ (float) $row['budget'] ? 'TSh '.$money::format($row['budget']) : 'No budget' }}</b>@if($showSpend)<span class="muted">spent {{ $money::format($row['recorded']) }}@if($row['used'] !== null) · {{ $money::format($row['used']) }}%@endif</span>@endif
          @if($showSpend && $row['used'] !== null)<div class="lg-meter" style="margin-top:4px"><span class="{{ (float) $row['used'] > 100 ? 'over' : ((float) $row['used'] >= 85 ? 'near' : '') }}" style="width:{{ min(100, (float) $row['used']) }}%"></span></div>@endif</td>
        @if($canEdit)<td><div class="lg-row-actions">
          @if($row['depth'] < \App\Support\PhaseTree::MAX_DEPTH)<button class="lg-link" type="button" data-add-child="{{ $phase->id }}" data-parent-name="{{ $row['label'] }}">+ {{ $row['depth'] === 1 ? 'Subphase' : 'Sub-subphase' }}</button>@endif
          <button class="lg-link" form="move-{{ $phase->id }}" name="direction" value="up" title="Move up">↑</button><button class="lg-link" form="move-{{ $phase->id }}" name="direction" value="down" title="Move down">↓</button>
          @if(!$row['count'])<button class="lg-link danger" form="delete-{{ $phase->id }}" onclick="return confirm('Delete {{ $row['label'] }} and anything under it?')">Delete</button>@endif
        </div></td>@endif
      </tr>
    @empty
      <tr><td colspan="7" class="lg-empty">No phases yet.@if($canEdit) Add the first phase above.@endif</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
@if($canEdit && $rows->isNotEmpty())<div style="margin-top:12px;display:flex;justify-content:flex-end"><button class="btn btn-primary" type="submit" form="budgetForm">Save Budget</button></div>@endif

@if($canEdit)
  @foreach($rows as $row)
    <form method="POST" action="{{ route('ledger.phases.rename', [$project, $row['phase']]) }}" id="rename-{{ $row['phase']->id }}">@csrf @method('PUT')</form>
    <form method="POST" action="{{ route('ledger.phases.move', [$project, $row['phase']]) }}" id="move-{{ $row['phase']->id }}">@csrf</form>
    <form method="POST" action="{{ route('ledger.phases.destroy', [$project, $row['phase']]) }}" id="delete-{{ $row['phase']->id }}">@csrf @method('DELETE')</form>
  @endforeach
  <dialog class="lg-dialog" id="childDialog"><form method="POST" action="{{ route('ledger.phases.store', $project) }}">@csrf
    <h3>Add under <span id="childParentName"></span></h3>
    <input type="hidden" name="parent_id" id="childParentId">
    <div class="field"><label for="child_name">Name <span class="req">*</span></label><input id="child_name" name="phase_name" maxlength="150" required placeholder="e.g. Ground floor, Tiling"></div>
    <div style="display:flex;gap:8px;justify-content:flex-end"><button class="btn btn-ghost" type="button" onclick="this.closest('dialog').close()">Cancel</button><button class="btn btn-primary" type="submit">Add</button></div>
  </form></dialog>
  <script>document.querySelectorAll('[data-add-child]').forEach(b => b.addEventListener('click', () => { document.getElementById('childParentId').value = b.dataset.addChild; document.getElementById('childParentName').textContent = b.dataset.parentName; document.getElementById('childDialog').showModal(); document.getElementById('child_name').focus(); }));</script>
@endif

@if(($showSpend || $canEdit) && $log->isNotEmpty())
<section class="lg-panel" style="margin-top:16px"><h3>Recent budget changes</h3>
  <div class="tablewrap" style="border:0"><table class="lg-table"><thead><tr><th>When</th><th>Phase</th><th>Category</th><th class="amt">Before</th><th class="amt">After</th><th>By</th></tr></thead><tbody>
  @foreach($log as $entry)<tr><td>{{ $entry->created_at->format('d M Y H:i') }}</td><td>{{ $tree->path($entry->document_id) ?: '—' }}</td><td>{{ $entry->item_description }}</td><td class="amt">{{ isset($entry->before_snapshot['amount']) && $entry->before_snapshot['amount'] !== null ? $money::format($entry->before_snapshot['amount']) : '—' }}</td><td class="amt">{{ isset($entry->after_snapshot['amount']) && $entry->after_snapshot['amount'] !== null ? $money::format($entry->after_snapshot['amount']) : '—' }}</td><td>{{ $entry->editor?->name }}</td></tr>@endforeach
  </tbody></table></div></section>
@endif
@endsection
