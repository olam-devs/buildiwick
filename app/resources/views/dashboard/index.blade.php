@extends('layouts.app')
@section('title', 'All Projects · Buildiwick')
@section('content')

<div class="topline">
  <div>
    <h2>All Projects</h2>
    <p>{{ $summary->active_projects }} projects &middot; computed live from every logged transaction, not a maintained spreadsheet</p>
  </div>
  <button type="button" class="btn btn-primary" onclick="Buildiwick.openDrawer()"><span style="font-size:16px;line-height:1;">+</span> New Entry</button>
</div>

<div class="tiles">
  <div class="tile"><div class="label">Active projects</div><div class="value">{{ $summary->active_projects }}</div></div>
  <div class="tile"><div class="label">Total expenses</div><div class="value">{{ number_format($summary->total) }}</div><div class="sub">TSh, all projects</div></div>
  <div class="tile"><div class="label">Cash paid out</div><div class="value">{{ number_format($summary->paid) }}</div></div>
  <div class="tile"><div class="label">Unpaid</div><div class="value">{{ number_format($summary->unpaid) }}</div></div>
</div>

<div class="tablewrap">
  <table>
    <thead>
      <tr><th>Project</th><th>Code</th><th>Materials</th><th>Labour</th><th>Logistics</th><th>Prelim.</th><th>Total</th><th>Status</th></tr>
    </thead>
    <tbody>
      @foreach ($rows as $row)
      <tr class="rowlink" onclick="window.location='{{ url('/projects/'.$row->project->id) }}'">
        <td class="name">{{ $row->project->name }}</td>
        <td class="code">{{ $row->project->project_code }}</td>
        <td class="amt">{{ number_format($row->categories['materials']) }}</td>
        <td class="amt">{{ number_format($row->categories['labour_subcontracts']) }}</td>
        <td class="amt">{{ number_format($row->categories['logistics_equipment']) }}</td>
        <td class="amt">{{ number_format($row->categories['preliminaries_overheads']) }}</td>
        <td class="amt">{{ number_format($row->total) }}</td>
        <td>
          @if ($row->has_activity)
            <span class="dot-status active">Active</span>
          @else
            <span class="dot-status zero">No entries</span>
          @endif
        </td>
      </tr>
      @endforeach
    </tbody>
  </table>
</div>

<footer>{{ $rows->count() }} projects &middot; click any row to open its own breakdown</footer>
@endsection
