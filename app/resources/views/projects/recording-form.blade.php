@extends('layouts.app')
@section('title', ($project ? $project->name.' · Settings' : 'New Project').' · Buildiwick')
@section('content')
@include('partials.ledger.styles')
<div class="lg-head">
  <div><h2>{{ $project ? 'Project Settings' : 'New Project' }}</h2><p>The same details as the top of each project sheet in the tracker: Project ID, name, status and location.</p></div>
  <div class="lg-actions"><a class="btn btn-ghost" href="{{ route('admin.projects.index') }}">All projects</a></div>
</div>
@if ($errors->any())<div class="flash error"><ul style="margin:0;padding-left:20px;">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<form method="POST" action="{{ $project ? route('admin.projects.update', $project) : route('admin.projects.store') }}" class="lg-panel lg-form">@csrf @if($project) @method('PUT') @endif
  <div class="grid">
    <div class="field"><label for="project_code">Project ID <span class="req">*</span></label><input id="project_code" name="project_code" value="{{ old('project_code', $project?->project_code) }}" placeholder="BWG-OYS-RES-2026-001" maxlength="50" required style="text-transform:uppercase"></div>
    <div class="field" style="grid-column:span 2"><label for="name">Project name <span class="req">*</span></label><input id="name" name="name" value="{{ old('name', $project?->name) }}" maxlength="150" required placeholder="Oysterbay villa build"></div>
    <div class="field"><label for="status">Status <span class="req">*</span></label><select id="status" name="status" required>@foreach($statuses as $status)<option value="{{ $status->code }}" @selected(old('status', $project?->status ?? 'active') === $status->code)>{{ $status->label }}</option>@endforeach</select></div>
    <div class="field" style="grid-column:span 2"><label for="location">Location <span class="req">*</span></label><input id="location" name="location" value="{{ old('location', $project?->location) }}" maxlength="150" required placeholder="Oysterbay, Dar es Salaam"></div>
    <div class="field" style="grid-column:1/-1"><label>Project Manager(s)</label>
      <div class="lg-cat-pick">@forelse($managers as $manager)<label><input type="checkbox" name="project_manager_ids[]" value="{{ $manager->id }}" @checked(in_array($manager->id, old('project_manager_ids', $assigned)))>{{ $manager->name }}</label>@empty<span class="form-context">No active Project Manager accounts yet. Add them under User Management.</span>@endforelse</div></div>
    <div class="field" style="grid-column:1/-1"><label for="notes">Notes</label><input id="notes" name="notes" value="{{ old('notes', $project?->notes) }}" maxlength="2000"></div>
  </div>
  @unless($project)
    <label style="display:flex;gap:8px;align-items:center;margin-top:14px;font-size:13px"><input type="checkbox" name="standard_stages" value="1" checked> Start with the tracker's standard stages ({{ implode(', ', $stages) }}). You can rename, nest or remove them afterwards.</label>
  @endunless
  <div style="margin-top:16px;display:flex;gap:8px"><button class="btn btn-primary" type="submit">{{ $project ? 'Save Settings' : 'Create Project' }}</button>
    @if($project)<a class="btn btn-ghost" href="{{ route('ledger.structure', $project) }}">Phases &amp; Budget</a>@endif</div>
</form>
@endsection
