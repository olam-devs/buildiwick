@extends('layouts.app')
@section('title', $project->name.' · Estimator')
@section('content')
<a class="back-link" href="{{ route('estimator.projects.index') }}">&larr; Back to Estimator Dashboard</a>
<div class="topline">
  <div><h2>{{ $project->name }}</h2><p>{{ $project->project_code }} · {{ $project->client?->name ?? 'Client not linked' }} · {{ $project->location ?? 'Location not set' }}</p></div>
  @if ($workingEstimate)
    <div class="estimate-actions">
      <span class="dot-status attn">Working draft · v{{ $workingEstimate->version }}</span>
      @if ($workingEstimate->entries->isNotEmpty())
        <form method="POST" action="{{ route('estimator.estimates.finalize', [$project, $workingEstimate]) }}" onsubmit="return confirm('Issue this estimate? The version will be locked for editing.');">@csrf<button class="btn btn-primary" type="submit">Issue Estimate</button></form>
      @endif
    </div>
  @elseif ($displayEstimate)
    <div class="estimate-actions"><span class="dot-status {{ $displayEstimate->status === 'issued' ? 'active' : 'zero' }}">{{ ucfirst($displayEstimate->status) }} · v{{ $displayEstimate->version }}</span><form method="POST" action="{{ route('estimator.estimates.start', $project) }}">@csrf<button class="btn btn-primary" type="submit">Start New Version</button></form></div>
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

@if (!$displayEstimate)
  <section class="panel empty-state">
    <h3>No estimate has been created for this project yet.</h3>
    <p>Create one working estimate for the client and add priced entries to any of the seven planned construction phases.</p>
    <form method="POST" action="{{ route('estimator.estimates.start', $project) }}">@csrf<button class="btn btn-primary" type="submit">Start Estimate</button></form>
  </section>
@else
  <section class="tiles estimate-summary-cards">
    <div class="tile"><div class="label">Total Base Cost</div><div class="value">{{ \App\Support\Money::format($projectSummary['baseTotal']) }}</div><div class="sub">TSh</div></div>
    <div class="tile"><div class="label">Total Markup Amount</div><div class="value">{{ \App\Support\Money::format($projectSummary['markupTotal']) }}</div><div class="sub">TSh</div></div>
    <div class="tile"><div class="label">Client Estimate</div><div class="value">{{ \App\Support\Money::format($projectSummary['clientTotal']) }}</div><div class="sub">TSh</div></div>
    <div class="tile"><div class="label">Effective Markup</div><div class="value">{{ \App\Support\Money::format($projectSummary['effectiveMarkup']) }}%</div><div class="sub">Markup ÷ base cost; not gross margin</div></div>
  </section>

  <section class="panel form-section">
    <h3>Project Category Summary · TSh</h3>
    <div class="tablewrap"><table style="min-width:600px;">
      <thead><tr><th>Category</th><th>Base Cost</th><th>Markup</th><th>Client Estimate</th></tr></thead>
      <tbody>
        @foreach ($projectSummary['categories'] as $category)
          <tr><td>{{ $category['label'] }}</td><td class="amt">{{ \App\Support\Money::format($category['base']) }}</td><td class="amt">{{ \App\Support\Money::format($category['markup']) }}</td><td class="amt">{{ \App\Support\Money::format($category['client']) }}</td></tr>
        @endforeach
      </tbody>
      <tfoot><tr><th>Project Base Cost</th><th class="amt">{{ \App\Support\Money::format($projectSummary['baseTotal']) }}</th><th class="amt">{{ \App\Support\Money::format($projectSummary['markupTotal']) }}</th><th class="amt">{{ \App\Support\Money::format($projectSummary['clientTotal']) }}</th></tr></tfoot>
    </table></div>
  </section>

  @foreach ($project->phases as $phase)
    @php($summary = $phaseSummaries[$phase->id])
    @php($phaseEntries = $displayEstimate->entries->where('project_phase_id', $phase->id)->sortBy('id'))
    <section class="panel form-section estimator-phase">
      <div class="phase-heading"><div><h3>Phase {{ $phase->phase_order }} — {{ $phase->stage_label }}</h3><p>{{ ucfirst(str_replace('_', ' ', $phase->status)) }} · {{ $phase->planned_start_date?->format('d M Y') ?? 'No planned date' }} – {{ $phase->planned_completion_date?->format('d M Y') ?? 'No planned completion' }}</p></div>
      @if ($workingEstimate)<a class="btn btn-primary" href="{{ route('estimator.entries.create', [$project, $phase]) }}">+ New Estimate Entry</a>@endif</div>

      <div class="tiles phase-metrics">
        @foreach ($summary['categories'] as $category)
          <div class="tile"><div class="label">{{ $category['label'] }}</div><div class="value">{{ \App\Support\Money::format($category['client']) }}</div><div class="sub">Client estimate · TSh</div></div>
        @endforeach
        <div class="tile"><div class="label">Phase Base Cost</div><div class="value">{{ \App\Support\Money::format($summary['baseTotal']) }}</div></div>
        <div class="tile"><div class="label">Phase Markup</div><div class="value">{{ \App\Support\Money::format($summary['markupTotal']) }}</div></div>
        <div class="tile"><div class="label">Phase Client Estimate</div><div class="value">{{ \App\Support\Money::format($summary['clientTotal']) }}</div></div>
      </div>

      @if ($phaseEntries->isEmpty())
        <div class="phase-empty">No estimate entries have been added to this phase yet.@if($workingEstimate) <a href="{{ route('estimator.entries.create', [$project, $phase]) }}">Add the first entry</a>.@endif</div>
      @else
        <div class="tablewrap"><table class="estimate-entry-table">
          <thead><tr><th>Item / Description</th><th>Category</th><th>Subcategory</th><th>Quantity</th><th>Unit</th><th>Base Unit Cost</th><th>Base Total</th><th>Markup %</th><th>Client Unit Price</th><th>Markup Amount</th><th>Client Total</th>@if($workingEstimate)<th>Action</th>@endif</tr></thead>
          <tbody>
            @foreach ($phaseEntries as $entry)
              <tr>
                <td>{{ $entry->item_description }}</td>
                <td>{{ $summary['categories'][$entry->main_category]['label'] ?? $entry->main_category }}</td>
                <td>{{ $entry->subcategory ? ($subcategoryLabels[$entry->subcategory] ?? $entry->subcategory) : '—' }}</td>
                <td class="amt">{{ $entry->quantity ?? '1.00' }}</td>
                <td>{{ $entry->unit ? ($unitLabels[$entry->unit] ?? $entry->unit) : 'LS' }}</td>
                <td class="amt">{{ \App\Support\Money::format($entry->base_unit_cost) }}</td>
                <td class="amt">{{ \App\Support\Money::format($entry->base_total) }}</td>
                <td class="amt">{{ $entry->markup_percent }}%</td>
                <td class="amt">{{ \App\Support\Money::format($entry->client_unit_cost) }}</td>
                <td class="amt">{{ \App\Support\Money::format($entry->markup_amount) }}</td>
                <td class="amt">{{ \App\Support\Money::format($entry->line_total) }}</td>
                @if($workingEstimate)<td class="entry-actions"><a href="{{ route('estimator.entries.edit', [$project, $phase, $entry]) }}">Edit</a><form method="POST" action="{{ route('estimator.entries.delete', [$project, $phase, $entry]) }}" onsubmit="return confirm('Delete this estimate entry?');">@csrf @method('DELETE')<button type="submit">Delete</button></form></td>@endif
              </tr>
            @endforeach
          </tbody>
          <tfoot><tr><th colspan="7">Phase totals</th><th class="amt">{{ \App\Support\Money::format($summary['baseTotal']) }}</th><th></th><th></th><th class="amt">{{ \App\Support\Money::format($summary['markupTotal']) }}</th><th class="amt">{{ \App\Support\Money::format($summary['clientTotal']) }}</th>@if($workingEstimate)<th></th>@endif</tr></tfoot>
        </table></div>
      @endif
    </section>
  @endforeach
@endif

@if ($history->isNotEmpty())
  <section class="panel form-section"><h3>Estimate Versions</h3><div class="tablewrap"><table style="min-width:500px;"><thead><tr><th>Estimate</th><th>Version</th><th>Status</th><th>Prepared</th><th>Client Estimate</th></tr></thead><tbody>@foreach($history as $row)<tr><td>{{ $row->estimate->estimate_code }}</td><td>{{ $row->estimate->version }}</td><td>{{ ucfirst($row->estimate->status) }}</td><td>{{ $row->estimate->prepared_at?->format('d M Y') ?? '—' }}</td><td class="amt">{{ \App\Support\Money::format($row->summary['clientTotal']) }}</td></tr>@endforeach</tbody></table></div></section>
@endif
@endsection