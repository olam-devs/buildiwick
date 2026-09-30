@extends('layouts.app')
@php($editing = $project !== null)
@section('title', ($editing ? 'Edit Project Setup' : 'New Project').' · Buildiwick')
@section('content')
<a class="back-link" href="{{ $editing ? route('admin.projects.show', $project) : route('admin.projects.index') }}">&larr; {{ $editing ? 'Back to project' : 'Back to project list' }}</a>
<div class="topline"><div><h2>{{ $editing ? 'Edit Project Setup' : 'Register Confirmed Project' }}</h2><p>One master project record shared across Buildiwick teams</p></div></div>

@if ($errors->any())
  <div class="flash error"><strong>Please correct these fields:</strong><ul style="margin:8px 0 0;padding-left:20px;">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<form method="POST" action="{{ $editing ? route('admin.projects.update', $project) : route('admin.projects.store') }}" enctype="multipart/form-data">
  @csrf
  @if ($editing) @method('PUT') @endif

  <section class="panel form-section">
    <h3>Project Information</h3>
    <div class="row2">
      <div class="field"><label for="project_code">Project Code <span class="req">*</span></label><input id="project_code" name="project_code" value="{{ old('project_code', $project?->project_code) }}" @readonly($editing) required maxlength="100"><div class="field-err">@error('project_code'){{ $message }}@enderror</div></div>
      <div class="field"><label for="name">Project Name <span class="req">*</span></label><input id="name" name="name" value="{{ old('name', $project?->name) }}" required maxlength="255"></div>
    </div>
    <div class="row2">
      <div class="field"><label for="location">Project Address / Location</label><input id="location" name="location" value="{{ old('location', $project?->location) }}" maxlength="255"></div>
      <div class="field"><label for="status">Project Status <span class="req">*</span></label><select id="status" name="status" required>@foreach ($statuses as $status)<option value="{{ $status->code }}" @selected(old('status', $project?->status ?? 'active') === $status->code)>{{ $status->label }}</option>@endforeach</select></div>
    </div>
  </section>

  <section class="panel form-section">
    <h3>Client</h3>
    <div class="row2">
      <div class="field"><label for="client_mode">Client option <span class="req">*</span></label><select id="client_mode" name="client_mode" onchange="document.getElementById('existingClientFields').hidden=this.value==='new';document.getElementById('newClientFields').hidden=this.value!=='new'"><option value="existing" @selected(old('client_mode', 'existing') === 'existing')>Select existing client</option><option value="new" @selected(old('client_mode') === 'new')>Create a new client</option></select></div>
      <div class="field" id="existingClientFields" @if(old('client_mode') === 'new') hidden @endif><label for="client_id">Existing Client <span class="req">*</span></label><select id="client_id" name="client_id"><option value="">Select client</option>@foreach ($clients as $client)<option value="{{ $client->id }}" @selected((string) old('client_id', $project?->client_id) === (string) $client->id)>{{ $client->name }}{{ $client->phone ? ' · '.$client->phone : '' }}</option>@endforeach</select></div>
    </div>
    <div id="newClientFields" @if(old('client_mode', 'existing') !== 'new') hidden @endif>
      <div class="row2">
        <div class="field"><label for="new_client_name">Client Name <span class="req">*</span></label><input id="new_client_name" name="new_client[name]" value="{{ old('new_client.name') }}" maxlength="255"></div>
        <div class="field"><label for="new_client_phone">Phone Number</label><input id="new_client_phone" name="new_client[phone]" value="{{ old('new_client.phone') }}" maxlength="50"></div>
      </div>
      <div class="row2">
        <div class="field"><label for="new_client_email">Email</label><input id="new_client_email" type="email" name="new_client[email]" value="{{ old('new_client.email') }}" maxlength="255"></div>
        <div class="field"><label for="new_client_address">Client Address</label><input id="new_client_address" name="new_client[address]" value="{{ old('new_client.address') }}" maxlength="2000"></div>
      </div>
      <div class="field"><label for="new_client_notes">Client Notes</label><textarea id="new_client_notes" name="new_client[notes]" rows="2">{{ old('new_client.notes') }}</textarea></div>
    </div>
  </section>

  <section class="panel form-section">
    <h3>Contract Information</h3>
    <div class="row2">
      <div class="field"><label for="contract_reference">Contract Reference / Number</label><input id="contract_reference" name="contract_reference" value="{{ old('contract_reference', $project?->contract_reference) }}" maxlength="255"></div>
      <div class="field"><label for="contract_signed">Contract Signed? <span class="req">*</span></label><select id="contract_signed" name="contract_signed" required><option value="0" @selected((string) old('contract_signed', $project?->contract_signed ? '1' : '0') === '0')>No</option><option value="1" @selected((string) old('contract_signed', $project?->contract_signed ? '1' : '0') === '1')>Yes</option></select></div>
    </div>
    <div class="row2">
      <div class="field"><label for="contract_signed_at">Contract Signing Date</label><input id="contract_signed_at" type="date" name="contract_signed_at" value="{{ old('contract_signed_at', $project?->contract_signed_at?->format('Y-m-d')) }}"></div>
      <div class="field"><label for="contract_document">Signed Contract Document @if(!$editing || !$project->contractDocuments->count())<span class="req">*</span>@endif</label><input id="contract_document" type="file" name="contract_document" accept=".pdf,.jpg,.jpeg,.png"><div class="field-err">PDF/JPG/PNG, maximum 20 MB. Stored privately.</div>@if($editing && $project->contractDocuments->isNotEmpty())<div class="field-err">Existing signed contract retained if no replacement is uploaded.</div>@endif</div>
    </div>
  </section>

  <section class="panel form-section">
    <h3>Project Programme</h3>
    <div class="row2">
      <div class="field"><label for="planned_start_date">Planned Project Start <span class="req">*</span></label><input id="planned_start_date" type="date" name="planned_start_date" value="{{ old('planned_start_date', $project?->planned_start_date?->format('Y-m-d')) }}" required></div>
      <div class="field"><label for="expected_completion_date">Overall Expected Completion <span class="req">*</span></label><input id="expected_completion_date" type="date" name="expected_completion_date" value="{{ old('expected_completion_date', $project?->expected_completion_date?->format('Y-m-d')) }}" required></div>
    </div>
    <p style="color:var(--ink-dim);">Phase date ranges may overlap when the construction programme requires it.</p>
    @foreach ($phaseLabels as $code => $label)
      @php($phase = $project?->phases->firstWhere('stage_code', $code))
      <div class="phase-schedule">
        <h4>Phase {{ $loop->iteration }} — {{ $label }}</h4>
        <div class="row2">
          <div class="field"><label for="phase_{{ $code }}_start">Planned Start <span class="req">*</span></label><input id="phase_{{ $code }}_start" type="date" name="phases[{{ $code }}][planned_start_date]" value="{{ old("phases.$code.planned_start_date", $phase?->planned_start_date?->format('Y-m-d')) }}" required></div>
          <div class="field"><label for="phase_{{ $code }}_end">Planned Completion <span class="req">*</span></label><input id="phase_{{ $code }}_end" type="date" name="phases[{{ $code }}][planned_completion_date]" value="{{ old("phases.$code.planned_completion_date", $phase?->planned_completion_date?->format('Y-m-d')) }}" required></div>
        </div>
      </div>
    @endforeach
  </section>

  <section class="panel form-section">
    <h3>Assigned Project Team</h3>
    @foreach (['estimator_qs' => 'Assigned Estimator / QS', 'project_manager' => 'Assigned Project Manager', 'site_operator' => 'Assigned Site Operator'] as $role => $label)
      @php($field = match($role) { 'estimator_qs' => 'estimator_id', 'project_manager' => 'project_manager_id', default => 'site_operator_id' })
      <div class="field"><label for="{{ $field }}">{{ $label }} <span class="req">*</span></label><select id="{{ $field }}" name="{{ $field }}" required><option value="">Select user</option>@foreach ($teamUsers[$role] as $user)<option value="{{ $user->id }}" @selected((string) old($field, $team[$role]?->id ?? '') === (string) $user->id)>{{ $user->name }} — {{ $user->email }}</option>@endforeach</select></div>
    @endforeach
  </section>

  <section class="panel form-section">
    <h3>Project Notes</h3>
    <div class="field"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="3" maxlength="5000">{{ old('notes', $project?->notes) }}</textarea></div>
  </section>

  <div style="display:flex;justify-content:flex-end;gap:8px;margin:16px 0 32px;">
    <a class="btn btn-ghost" href="{{ $editing ? route('admin.projects.show', $project) : route('admin.projects.index') }}">Cancel</a>
    <button class="btn btn-primary" type="submit">{{ $editing ? 'Save Project Setup' : 'Register Project' }}</button>
  </div>
</form>
@endsection