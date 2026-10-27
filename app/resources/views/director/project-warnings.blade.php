@extends('layouts.app')
@section('title', $project->name.' · Warnings & KPIs')
@section('content')
<div class="topline">
  <div><h2>{{ $project->name }} · Warnings &amp; KPIs</h2><p>{{ $project->project_code }} · warnings, targets and warning limits for this project only. Company-wide targets and default limits are outside the project, in the main sidebar.</p></div>
</div>

@if ($errors->any())<div class="flash error"><ul style="margin:0;padding-left:20px;">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<section class="panel form-section">
  <h3>Current warnings</h3>
  @if(count($projectWarnings))
    <div class="warning-list">@foreach($projectWarnings as $warning)<div class="warning-item"><div><strong>{{ $warning['label'] }}</strong><span>{{ $warning['message'] }}</span></div></div>@endforeach</div>
  @else
    <p class="form-context">No warning rule is triggered for this project.</p>
  @endif
</section>

@can('manage-director-strategy')
<section class="panel form-section">
  <h3>Add a target for this project</h3>
  @include('director.partials.kpi-form', ['action' => route('director.projects.kpis.store', $project)])
</section>
@endcan

@include('director.partials.kpi-groups', ['groups' => $groups])

<section class="panel form-section">
  <h3>Warning limits for this project</h3>
  <p class="form-context">Change a limit to override the company default for this project only.</p>
  @include('director.partials.warning-rules-form', ['rules' => $rules, 'action' => route('director.projects.warning-rules.save', $project), 'project' => $project])
  @can('manage-director-strategy')@if(collect($rules)->contains('source', 'project'))<form method="POST" action="{{ route('director.projects.warning-rules.reset', $project) }}" style="margin-top:8px">@csrf @method('DELETE')<button class="btn btn-ghost" type="submit">Use company defaults</button></form>@endif @endcan
</section>
@include('director.partials.kpi-styles')
@endsection
