@extends('layouts.app')
@section('title', $project->name.' · Estimator')
@section('content')
<a class="back-link" href="{{ route('estimator.projects.index') }}">&larr; Back to Estimator Dashboard</a>
<div class="topline">
  <div><h2>{{ $project->name }}</h2><p>{{ $project->project_code }} · {{ $project->client?->name ?? 'Client not linked' }} · {{ $project->location ?? 'Location not set' }}</p></div>
  @if ($workingEstimate)
    <div class="estimate-actions">
      <a class="btn btn-ghost" href="{{ route('estimator.estimates.export', [$project, $workingEstimate]) }}">Download Excel</a>
      <span class="dot-status attn">Working draft · v{{ $workingEstimate->version }}</span>
      @if ($workingEstimate->entries->isNotEmpty())
        <form method="POST" action="{{ route('estimator.estimates.finalize', [$project, $workingEstimate]) }}" onsubmit="return confirm('Issue this estimate? The version will be locked for editing.');">@csrf<button class="btn btn-primary" type="submit">Issue Estimate</button></form>
      @endif
    </div>
  @elseif ($displayEstimate)
    <div class="estimate-actions"><span class="dot-status {{ $displayEstimate->status === 'issued' ? 'active' : 'zero' }}">{{ ucfirst($displayEstimate->status) }} · v{{ $displayEstimate->version }}</span><a class="btn btn-ghost" href="{{ route('estimator.estimates.export', [$project, $displayEstimate]) }}">Download Excel</a>@if($displayEstimate->status === 'issued')<form method="POST" action="{{ route('estimator.estimates.edit', [$project,$displayEstimate]) }}" class="edit-document-form">@csrf<div class="field"><label for="estimate_edit_reason">Why is this estimate being edited? <span class="req">*</span></label><input id="estimate_edit_reason" name="edit_reason" maxlength="2000" required></div><button class="btn btn-primary" type="submit">Edit Estimate</button></form>@endif</div>
  @endif
</div>

<section class="panel form-section">
  <h3>Project Estimate</h3>
  <div class="tiles">
    <div class="tile"><div class="label">Project</div><div>{{ $project->name }}</div></div>
    <div class="tile"><div class="label">Project Code</div><div>{{ $project->project_code }}</div></div>
    <div class="tile"><div class="label">Client</div><div>{{ $project->client?->name ?? '—' }}</div></div>
    <div class="tile"><div class="label">Location</div><div>{{ $project->location ?? '—' }}</div></div>
    <div class="tile"><div class="label">Current Operational Phase</div><div>{{ $project->activePhase?->stage_label ?? 'Not started' }}</div></div>
    <div class="tile"><div class="label">Estimate Status</div><div>{{ $displayEstimate ? ucfirst($displayEstimate->status).' · version '.$displayEstimate->version : 'Not started' }}</div></div>
  </div>
</section>

<section class="panel form-section">
  <div class="phase-heading"><div><h3>Project Phases</h3><p>Set phases for this project before issuing the estimate. Draft phases and their draft lines can be changed, deleted, or reset freely.</p></div>
  @if(!$phasesLocked)<form method="POST" action="{{ route('estimator.phases.reset', $project) }}" onsubmit="return confirm('Reset all draft phases? This also removes draft estimate and draft budget lines linked to them.');">@csrf<button class="btn btn-ghost" type="submit">Reset to Default</button></form>@endif</div>
  @if($phasesLocked)
    <div class="flash">Phase structure is locked because an estimate or internal budget has been approved. Later document corrections use the audited edit workflow.</div>
  @else
    <div class="phase-editor-list">
      @foreach($project->phases as $phase)
        <form method="POST" action="{{ route('estimator.phases.update', [$project,$phase]) }}" class="phase-editor-row">@csrf @method('PUT')
          <strong>{{ $phase->phase_order }}</strong><input name="phase_name" value="{{ $phase->stage_label }}" maxlength="150" required><input type="date" name="planned_start_date" value="{{ $phase->planned_start_date?->format('Y-m-d') }}"><input type="date" name="planned_completion_date" value="{{ $phase->planned_completion_date?->format('Y-m-d') }}"><button class="btn btn-ghost" type="submit">Save</button>
        </form>
        <form method="POST" action="{{ route('estimator.phases.delete', [$project,$phase]) }}" class="phase-delete-form" onsubmit="return confirm('Delete this draft phase and all draft entries linked to it?');">@csrf @method('DELETE')<button type="submit">Delete</button></form>
      @endforeach
    </div>
    <form method="POST" action="{{ route('estimator.phases.store', $project) }}" class="phase-add-row">@csrf<input name="phase_name" maxlength="150" placeholder="New phase name" required><input type="date" name="planned_start_date"><input type="date" name="planned_completion_date"><button class="btn btn-primary" type="submit">Add Phase</button></form>
  @endif
</section>

@if (!$displayEstimate)
  <section class="panel empty-state">
    <h3>No estimate has been created for this project yet.</h3>
    <p>Create one working estimate for the client and add priced entries to the project-specific construction phases above.</p>
    <form method="POST" action="{{ route('estimator.estimates.start', $project) }}">@csrf<button class="btn btn-primary" type="submit">Start Estimate</button></form>
  </section>
@else
  <section class="tiles estimate-summary-cards">
    <div class="tile"><div class="label">Total Base Cost</div><div class="value">{{ \App\Support\Money::format($projectSummary['baseTotal']) }}</div><div class="sub">TSh</div></div>
    <div class="tile"><div class="label">Total Markup Amount</div><div class="value">{{ \App\Support\Money::format($projectSummary['markupTotal']) }}</div><div class="sub">TSh</div></div>
    <div class="tile"><div class="label">Estimation Total</div><div class="value">{{ \App\Support\Money::format($projectSummary['clientTotal']) }}</div><div class="sub">TSh · complete client quotation</div></div>
    <div class="tile"><div class="label">Effective Markup</div><div class="value">{{ \App\Support\Money::format($projectSummary['effectiveMarkup']) }}%</div><div class="sub">Markup ÷ base cost; not gross margin</div></div>
  </section>

  <section class="panel form-section"><div class="phase-heading"><div><h3>Estimation by Phase</h3><p>Open one phase to see its organized quotation sections and subtotals.</p></div></div><div class="phase-summary-list">
  @foreach ($project->phases as $phase) @php($summary=$phaseSummaries[$phase->id]) @php($count=$displayEstimate->entries->where('project_phase_id',$phase->id)->count())
    <div class="phase-summary-row"><div><strong>Phase {{ $phase->phase_order }} · {{ $phase->stage_label }}</strong><small>{{ $count }} quotation line(s) · {{ ucfirst(str_replace('_',' ',$phase->status)) }}</small></div><div class="phase-summary-total"><span>Phase Total</span><strong>TSh {{ \App\Support\Money::format($summary['clientTotal']) }}</strong></div><a class="btn btn-ghost" href="{{ route('estimator.projects.show',[$project,'phase'=>$phase->id]).'#phase-detail' }}">Open Phase Estimation</a></div>
  @endforeach</div><footer class="document-grand-total"><span>Estimation Total</span><strong>TSh {{ \App\Support\Money::format($projectSummary['clientTotal']) }}</strong></footer></section>

  @if($selectedPhaseId)
    @php($phase=$project->phases->firstWhere('id',$selectedPhaseId)) @php($summary=$phaseSummaries[$phase->id]) @php($phaseEntries=$displayEstimate->entries->where('project_phase_id',$phase->id)->sortBy('id'))
    <section class="panel form-section estimator-phase" id="phase-detail"><div class="phase-heading"><div><a class="back-link" href="{{ route('estimator.projects.show',$project) }}">&larr; All phase estimations</a><h3>Phase {{ $phase->phase_order }} · {{ $phase->stage_label }}</h3><p>Each subphase is named once. Its lettered quotation lines and subtotal are shown below.</p></div>@if($workingEstimate)<a class="btn btn-primary" href="{{ route('estimator.entries.create',[$project,$phase]) }}">+ New Estimate Entry</a>@endif</div>
    @forelse($phaseEntries->groupBy(fn($entry)=>$entry->work_section ?: 'General Work') as $sectionName=>$sectionEntries)
      <article class="estimate-section"><h4>{{ $sectionName }}</h4><div class="tablewrap"><table><thead><tr><th>Item</th><th>Description</th><th>Quantity</th><th>Unit</th><th>Rate</th><th>Amount</th>@if($workingEstimate)<th>Action</th>@endif</tr></thead><tbody>
      @foreach($sectionEntries as $entry)<tr><td>{{ $entry->item_reference ?: '—' }}</td><td>{{ $entry->item_description }}</td><td class="amt">{{ $entry->quantity ?? '1.00' }}</td><td>{{ $unitLabels[$entry->unit] ?? strtoupper($entry->unit ?? 'LS') }}</td><td class="amt">{{ \App\Support\Money::format($entry->client_unit_cost) }}</td><td class="amt">{{ \App\Support\Money::format($entry->line_total) }}</td>@if($workingEstimate)<td class="entry-actions"><a href="{{ route('estimator.entries.edit',[$project,$phase,$entry]) }}">Edit</a><form method="POST" action="{{ route('estimator.entries.delete',[$project,$phase,$entry]) }}" onsubmit="@if($workingEstimate->version > 1) const reason=prompt('Why is this approved estimate line being deleted?');if(!reason)return false;this.edit_reason.value=reason; @endif return confirm('Delete this draft estimate entry?');">@csrf @method('DELETE')<input type="hidden" name="edit_reason"><button type="submit">Delete</button></form></td>@endif</tr>@endforeach
      </tbody><tfoot><tr><th colspan="5">{{ $sectionName }} Subtotal</th><th class="amt">{{ \App\Support\Money::format(\App\Support\Money::sum($sectionEntries->map(fn($entry)=>$entry->line_total))) }}</th>@if($workingEstimate)<th></th>@endif</tr></tfoot></table></div></article>
    @empty<div class="phase-empty">No estimate entries have been added to this phase.</div>@endforelse
    <footer class="document-grand-total"><span>{{ $phase->stage_label }} Estimation Total</span><strong>TSh {{ \App\Support\Money::format($summary['clientTotal']) }}</strong></footer></section>
  @endif
@endif

@if ($history->isNotEmpty())
  <section class="panel form-section"><h3>Estimate Versions</h3><div class="tablewrap"><table style="min-width:500px;"><thead><tr><th>Estimate</th><th>Version</th><th>Status</th><th>Prepared</th><th>Client Estimate</th></tr></thead><tbody>@foreach($history as $row)<tr><td>{{ $row->estimate->estimate_code }}</td><td>{{ $row->estimate->version }}</td><td>{{ ucfirst($row->estimate->status) }}</td><td>{{ $row->estimate->prepared_at?->format('d M Y') ?? '—' }}</td><td class="amt">{{ \App\Support\Money::format($row->summary['clientTotal']) }}</td></tr>@endforeach</tbody></table></div></section>
@endif
@include('partials.project-edit-log', ['editLogTitle' => 'Estimate Edit Log'])
<style>.edit-document-form{display:flex;gap:8px;align-items:end}.edit-document-form .field{margin:0;min-width:260px}.phase-summary-list{display:grid;gap:8px}.phase-summary-row{display:grid;grid-template-columns:minmax(220px,1fr) 180px auto;align-items:center;gap:14px;padding:12px;border:1px solid var(--line);border-radius:8px}.phase-summary-row small,.phase-summary-total span{display:block;color:var(--ink-dim);font-size:11px;margin-top:3px}.phase-summary-total{text-align:right}.estimate-section{margin:14px 0}.estimate-section h4{padding:10px 12px;margin:0;background:var(--primary-soft);border:1px solid var(--line);border-bottom:0;border-radius:8px 8px 0 0}.estimate-section .tablewrap{border-radius:0 0 8px 8px}.document-grand-total{display:flex;justify-content:space-between;gap:12px;padding:14px;margin-top:14px;background:var(--surface-2);border-top:2px solid var(--line-strong);font-size:15px}@media(max-width:750px){.phase-summary-row{grid-template-columns:1fr}.phase-summary-total{text-align:left}}</style>
<style>.phase-editor-list{display:grid;grid-template-columns:1fr auto;gap:8px;margin:14px 0}.phase-editor-row,.phase-add-row{display:grid;grid-template-columns:34px minmax(180px,1fr) 155px 155px auto;gap:8px;align-items:center}.phase-delete-form{display:flex;align-items:center}.phase-delete-form button{color:#b42318;background:none;border:0;cursor:pointer}.phase-add-row{grid-template-columns:minmax(180px,1fr) 155px 155px auto;margin-top:12px}@media(max-width:850px){.phase-editor-list{grid-template-columns:1fr}.phase-editor-row,.phase-add-row{grid-template-columns:1fr}.phase-delete-form{margin-bottom:10px}}</style>
@endsection
