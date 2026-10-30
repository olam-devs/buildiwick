@extends('layouts.app')
@section('title', 'To Confirm · Buildiwick')
@section('content')
@include('partials.ledger.styles')
@php($money = \App\Support\Money::class)
<div class="lg-head">
  <div><h2>To Confirm</h2><p>Projects with lines still waiting for the amount paid. Open a project to confirm its lines, oldest first.</p></div>
  <div class="lg-actions"><input type="search" id="projectSearch" placeholder="Search projects…" class="lg-search"></div>
</div>

<div class="lg-cards">
  <div class="lg-card warn"><div class="k">Waiting</div><div class="v">{{ number_format($count) }} lines</div><div class="s">across {{ $rows->count() }} projects</div></div>
  <div class="lg-card"><div class="k">Amount to confirm</div><div class="v">TSh {{ $money::format($total) }}</div></div>
</div>

<div class="lg-scroll" style="max-height:none">
  <table class="lg-table lg-cards-sm" id="projectTable">
    <thead><tr><th style="width:40px">#</th><th>Project</th><th class="amt">Lines waiting</th><th class="amt">Amount to confirm</th><th>Oldest line</th><th></th></tr></thead>
    <tbody>
    @forelse($rows as $index => $row)
      <tr class="lg-row-link" data-search="{{ strtolower($row['project']->name.' '.$row['project']->project_code) }}" data-href="{{ route('ledger.expenses', [$row['project'], 'status' => 'to_confirm', 'sort' => 'oldest']) }}">
        <td data-label="#" class="lg-num">{{ $index + 1 }}</td>
        <td data-label="Project"><b>{{ $row['project']->name }}</b><span class="muted">{{ $row['project']->project_code }}</span></td>
        <td data-label="Lines waiting" class="amt">{{ number_format($row['count']) }}</td>
        <td data-label="Amount" class="amt"><b>{{ $money::format($row['amount']) }}</b></td>
        <td data-label="Oldest">{{ $row['oldest'] ? \Carbon\Carbon::parse($row['oldest'])->format('d M Y') : '—' }}</td>
        <td><a class="btn btn-primary" style="padding:5px 12px" href="{{ route('ledger.expenses', [$row['project'], 'status' => 'to_confirm', 'sort' => 'oldest']) }}">Open</a></td>
      </tr>
    @empty
      <tr><td colspan="6" class="lg-empty">Nothing is waiting. Every recorded line has been confirmed.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
@include('partials.ledger.list-script', ['table' => 'projectTable', 'search' => 'projectSearch'])
@endsection
