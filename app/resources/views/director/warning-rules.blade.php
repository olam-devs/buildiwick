@extends('layouts.app')
@section('title', 'Warning Rules · Buildiwick')
@section('content')
<a class="back-link" href="{{ route('director.index') }}">&larr; Back to Director Dashboard</a>
<div class="topline">
  <div><h2>Warning Rules</h2><p>Company defaults for project warnings. Every project uses these unless its own project page sets different limits.</p></div>
  <div class="director-actions"><a class="btn btn-ghost" href="{{ route('director.kpis.index') }}">KPI Targets</a></div>
</div>
@if ($errors->any())<div class="flash error"><ul style="margin:0;padding-left:20px;">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<section class="panel form-section">
  <h3>Company defaults</h3>
  @include('director.partials.warning-rules-form', ['rules' => $rules, 'action' => route('director.warning-rules.save')])
</section>
@include('director.partials.kpi-styles')
<style>.director-actions{display:flex;gap:8px}</style>
@endsection
