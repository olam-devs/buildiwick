@extends('layouts.app')
@section('title', 'Default Warning Rules · Buildiwick')
@section('content')
<div class="topline">
  <div><h2>Default Warning Rules</h2><p>Company defaults for project warnings. Every project uses these unless different limits are set inside that project under <strong>Warnings &amp; KPIs</strong>.</p></div>
</div>
@if ($errors->any())<div class="flash error"><ul style="margin:0;padding-left:20px;">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<section class="panel form-section">
  <h3>Company defaults</h3>
  @include('director.partials.warning-rules-form', ['rules' => $rules, 'action' => route('director.warning-rules.save')])
</section>
@include('director.partials.kpi-styles')
@endsection
