@extends('layouts.app')
@section('title', 'Company KPI Targets · Buildiwick')
@section('content')
<div class="topline">
  <div><h2>Company KPI Targets</h2><p>Targets for the whole company over a month, quarter, year or custom period. Targets for a single project are set inside that project under <strong>Warnings &amp; KPIs</strong>.</p></div>
</div>

@if ($errors->any())<div class="flash error"><ul style="margin:0;padding-left:20px;">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

@can('manage-director-strategy')
<section class="panel form-section">
  <h3>Add a company target</h3>
  @include('director.partials.kpi-form', ['action' => route('director.kpis.store')])
</section>
@endcan

@include('director.partials.kpi-groups', ['groups' => $groups])
@include('director.partials.kpi-styles')
@endsection
