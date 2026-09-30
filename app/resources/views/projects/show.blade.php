@extends('layouts.app')
@section('title', $project->name.' · Project Setup')
@section('content')
<a class="back-link" href="{{ route('admin.projects.index') }}">&larr; Back to Project Setup</a>
<div class="topline">
  <div><h2>{{ $project->name }}</h2><p>{{ $project->project_code }} · {{ $project->client?->name }}</p></div>
  <a class="btn btn-primary" href="{{ route('admin.projects.edit', $project) }}">Edit Project Setup</a>
</div>

<section class="panel form-section">
  <h3>Project Information</h3>
  <div class="tiles">
    <div class="tile"><div class="label">Project Name</div><div>{{ $project->name }}</div></div>
    <div class="tile"><div class="label">Project Code</div><div>{{ $project->project_code }}</div></div>
    <div class="tile"><div class="label">Address</div><div>{{ $project->location ?: '—' }}</div></div>
    <div class="tile"><div class="label">Project Status</div><div>{{ str_replace('_', ' ', $project->status) }}</div></div>
    <div class="tile"><div class="label">Planned Start</div><div>{{ $project->planned_start_date?->format('d M Y') ?? '—' }}</div></div>
    <div class="tile"><div class="label">Expected Completion</div><div>{{ $project->expected_completion_date?->format('d M Y') ?? '—' }}</div></div>
  </div>
</section>

<section class="panel form-section">
  <h3>Client</h3>
  <div class="row2"><div><strong>{{ $project->client?->name ?? '—' }}</strong></div><div>Phone: {{ $project->client?->phone ?: '—' }}</div><div>Email: {{ $project->client?->email ?: '—' }}</div><div>Address: {{ $project->client?->address ?: '—' }}</div></div>
</section>

<section class="panel form-section">
  <h3>Contract</h3>
  <div class="row2"><div>Reference: {{ $project->contract_reference ?: '—' }}</div><div>Signed: {{ $project->contract_signed ? 'Yes' : 'No' }}</div><div>Signing Date: {{ $project->contract_signed_at?->format('d M Y') ?? '—' }}</div></div>
  @forelse ($project->contractDocuments as $document)
    <div class="attn-row"><span>{{ $document->file_name }}@if($document->uploadedBy) · uploaded by {{ $document->uploadedBy->name }}@endif</span><a class="btn btn-ghost" href="{{ route('admin.projects.contracts.download', [$project, $document]) }}">View / Download Contract</a></div>
  @empty
    <p style="color:var(--ink-dim);">No contract document attached.</p>
  @endforelse
</section>

<section class="panel form-section">
  <h3>Assigned Project Team</h3>
  <div class="row3">
    <div><div class="label">Estimator / QS</div><strong>{{ $team['estimator_qs']?->name ?? '—' }}</strong></div>
    <div><div class="label">Project Manager</div><strong>{{ $team['project_manager']?->name ?? '—' }}</strong></div>
    <div><div class="label">Site Operator</div><strong>{{ $team['site_operator']?->name ?? '—' }}</strong></div>
  </div>
</section>

<section class="panel form-section">
  <h3>Construction Programme</h3>
  <div class="tablewrap"><table style="min-width:720px;">
    <thead><tr><th>Phase</th><th>Planned Start</th><th>Planned Completion</th><th>Actual Start</th><th>Actual Completion</th><th>Status</th></tr></thead>
    <tbody>@foreach ($project->phases as $phase)<tr><td>{{ $phase->stage_label }}</td><td>{{ $phase->planned_start_date?->format('d M Y') ?? '—' }}</td><td>{{ $phase->planned_completion_date?->format('d M Y') ?? '—' }}</td><td>{{ $phase->actual_start_date?->format('d M Y') ?? '—' }}</td><td>{{ $phase->actual_completion_date?->format('d M Y') ?? '—' }}</td><td>{{ str_replace('_', ' ', $phase->status) }}</td></tr>@endforeach</tbody>
  </table></div>
</section>

@if ($project->notes)<section class="panel form-section"><h3>Project Notes</h3><p>{{ $project->notes }}</p></section>@endif
@endsection