@extends('layouts.app')
@section('title', $project->name.' · Buildiwick')
@section('content')

@php
  $labels = [
    'materials' => 'Materials',
    'labour_subcontracts' => 'Labour & Subcontracts',
    'logistics_equipment' => 'Logistics & Equipment',
    'preliminaries_overheads' => 'Preliminaries & Overheads',
  ];
  $max = max(array_merge($categories, [1]));
@endphp

<a class="back-link" href="{{ url('/projects') }}">&larr; Back to all projects</a>

<div class="topline">
  <div>
    <h2>{{ $project->name }}</h2>
    <p>{{ $project->project_code }} @if($project->location) &middot; {{ $project->location }} @endif</p>
  </div>
  <button type="button" class="btn btn-primary" onclick="Buildiwick.openDrawer()"><span style="font-size:16px;line-height:1;">+</span> New Entry</button>
</div>

<div class="tiles">
  @foreach ($categories as $code => $amount)
  <div class="tile"><div class="label">{{ $labels[$code] }}</div><div class="value">{{ number_format($amount) }}</div></div>
  @endforeach
  <div class="tile"><div class="label">Total cost to date</div><div class="value">{{ number_format($total) }}</div><div class="sub">no client revenue shown here</div></div>
</div>

<div class="panelrow">
  <div class="panel">
    <h3>Spend by category</h3>
    @foreach ($categories as $code => $amount)
    <div class="bar-row">
      <span>{{ $labels[$code] }}</span>
      <div class="bar-track"><div class="bar-fill" style="width:{{ $max > 0 ? round($amount / $max * 100) : 0 }}%"></div></div>
      <span class="bar-amt">{{ number_format($amount) }}</span>
    </div>
    @endforeach
  </div>
  <div class="panel">
    <h3>Labour &amp; subcontract agreements</h3>
    @forelse ($agreements as $row)
      <div class="attn-row">
        <span>{{ $row->agreement->fundi_subcontractor }} &middot; {{ $row->agreement->scope_of_work }}</span>
        <span class="meta">paid {{ number_format($row->paid_to_date) }} / {{ number_format($row->agreement->agreed_value) }}</span>
      </div>
    @empty
      <p style="color:var(--ink-dim);font-size:13px;font-style:italic;margin:0;">No labour agreements on this project yet.</p>
    @endforelse
  </div>
</div>

<div class="tablewrap" style="margin-bottom:20px;">
  <table>
    <thead><tr><th>Transaction</th><th>Category</th><th>Description</th><th>Amount</th><th>Status</th></tr></thead>
    <tbody>
      @forelse ($needsAttention as $t)
      <tr>
        <td class="code">{{ $t->transaction_code }}</td>
        <td>{{ $labels[$t->main_category] ?? $t->main_category }}</td>
        <td>{{ $t->item_description }}</td>
        <td class="amt">{{ number_format($t->total_amount) }}</td>
        <td><span class="badge warn">{{ str_replace('_', ' ', $t->data_status) }}</span></td>
      </tr>
      @empty
      <tr><td colspan="5" style="color:var(--ink-dim);font-style:italic;">Nothing waiting on this project.</td></tr>
      @endforelse
    </tbody>
  </table>
</div>

<footer>{{ $needsAttention->count() }} entries still need confirming on this project</footer>
@endsection
@push('drawers')
@include('partials.new-entry-drawer')
@endpush
