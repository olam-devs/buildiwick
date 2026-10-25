@extends('layouts.app')
@section('title', 'KPI Targets · Buildiwick')
@section('content')
@php($money = \App\Support\Money::class)
<a class="back-link" href="{{ route('director.index') }}">&larr; Back to Director Dashboard</a>
<div class="topline">
  <div><h2>KPI Targets</h2><p>Set targets for a month, quarter, year or custom period, for the whole company or one project. Actual figures come from recorded payments, issued invoices and budgets.</p></div>
  <div class="director-actions"><a class="btn btn-ghost" href="{{ route('director.warning-rules.index') }}">Warning Rules</a></div>
</div>

@if ($errors->any())<div class="flash error"><ul style="margin:0;padding-left:20px;">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

@can('manage-director-strategy')
<section class="panel form-section">
  <h3>Add a target</h3>
  <form method="POST" action="{{ route('director.kpis.store') }}" class="kpi-form">@csrf
    <div class="field"><label for="metric">Measure <span class="req">*</span></label><select id="metric" name="metric" required>@foreach(\App\Services\DirectorKpiService::METRICS as $code => $metric)<option value="{{ $code }}" @selected(old('metric') === $code)>{{ $metric['label'] }}</option>@endforeach</select></div>
    <div class="field"><label for="project_id">For</label><select id="project_id" name="project_id"><option value="">Whole company</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected((string) old('project_id') === (string) $project->id)>{{ $project->name }}</option>@endforeach</select></div>
    <div class="field"><label for="period">Period <span class="req">*</span></label><select id="period" name="period" required>@foreach(\App\Services\DirectorKpiService::PERIODS as $code => $label)<option value="{{ $code }}" @selected(old('period', 'this_quarter') === $code)>{{ $label }}</option>@endforeach</select></div>
    <div class="field"><label for="from">From (custom)</label><input type="date" id="from" name="from" value="{{ old('from') }}"></div>
    <div class="field"><label for="to">To (custom)</label><input type="date" id="to" name="to" value="{{ old('to') }}"></div>
    <div class="field"><label for="target_value">Target (TSh, or % for budget used) <span class="req">*</span></label><input id="target_value" name="target_value" type="number" min="0" step="0.01" value="{{ old('target_value') }}" required></div>
    <div class="field"><label for="notes">Notes</label><input id="notes" name="notes" maxlength="1000" value="{{ old('notes') }}"></div>
    <button class="btn btn-primary" type="submit">Save Target</button>
  </form>
</section>
@endcan

@foreach ($groups as $title => $rows)
<section class="panel form-section">
  <h3>{{ $title }} targets</h3>
  <div class="tablewrap"><table style="min-width:900px"><thead><tr><th>Measure</th><th>For</th><th>Period</th><th class="amt">Target</th><th class="amt">Actual</th><th>Progress</th><th>Status</th>@can('manage-director-strategy')<th></th>@endcan</tr></thead><tbody>
  @forelse ($rows as $row)
    @include('director.partials.kpi-row', ['row' => $row, 'showProject' => true, 'showDelete' => true])
  @empty
    <tr><td colspan="8">No {{ strtolower($title) }} targets.</td></tr>
  @endforelse
  </tbody></table></div>
</section>
@endforeach
<style>.kpi-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;align-items:end}.kpi-form .field{margin:0}.director-actions{display:flex;gap:8px}</style>
@include('director.partials.kpi-styles')
@endsection
