@extends('layouts.app')
@section('title', 'Company Overheads · Buildiwick')
@section('content')
@include('partials.ledger.styles')
<div class="lg-head">
  <div><h2>Company Overheads</h2><p>Office, staff, statutory and running costs. Only the Director sees these, and they are not part of any project total.</p></div>
</div>
<div class="lg-scroll" style="max-height:none">
  <table class="lg-table lg-cards-sm">
    <thead><tr><th>Ledger</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse($ledgers as $ledger)
      <tr>
        <td data-label="Ledger"><b>{{ $ledger->name }}</b><span class="muted">{{ $ledger->project_code }}</span></td>
        <td data-label="Status">{{ ucfirst(str_replace('_', ' ', $ledger->status)) }}</td>
        <td class="lg-btn-cell"><div class="lg-btns"><a class="lg-btn" href="{{ route('ledger.overview', $ledger) }}">Open</a></div></td>
      </tr>
    @empty
      <tr><td colspan="3" class="lg-empty">No overheads ledger yet. In Project Setup, create one and tick "Company overheads".</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
@endsection
