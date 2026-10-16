@extends('layouts.app')
@section('title', $budget->phase?->stage_label.' · '.$categoryLabel.' Budget')
@section('content')
@php
  $money = '\App\Support\Money';
  $lineTotal = fn($entry) => \App\Support\Money::product($entry->unit_cost ?? 0, $entry->quantity ?? 1);
@endphp
<a class="back-link" href="{{ route('director.projects.show',$project).'#budget' }}">&larr; Back to {{ $project->name }} Internal Budgets</a>
<div class="topline"><div><h2>{{ $budget->phase?->stage_label }} · {{ $categoryLabel }} Budget</h2><p>{{ $project->project_code }} · {{ $budget->budget_code }} · {{ ucfirst($budget->status) }} · Version {{ $budget->version }}</p></div><a class="btn btn-ghost" href="{{ route('pm.budgets.export',[$project,$budget]) }}">Download Excel</a></div>

<section class="panel form-section">
@forelse($budget->entries->sortBy(fn($entry)=>[$entry->work_area,$entry->construction_category,$entry->work_package_code,$entry->id])->groupBy(fn($entry)=>$entry->work_area ?: 'General Work Area') as $areaName=>$areaEntries)
  <div class="budget-area"><h3>{{ $areaName }}</h3>
  @foreach($areaEntries->groupBy(fn($entry)=>$entry->construction_category ?: 'General Construction Work') as $constructionName=>$constructionEntries)
    <h4>{{ $constructionName }}</h4>
    @foreach($constructionEntries->groupBy(fn($entry)=>($entry->work_package_code ?: 'General').'|'.($entry->work_package_name ?: 'General Work')) as $packageKey=>$packageEntries)
      @php([$packageCode,$packageName]=explode('|',$packageKey,2))
      <article class="budget-package"><div class="package-title"><strong>{{ $packageCode }} · {{ $packageName }}</strong></div><div class="tablewrap"><table><thead><tr><th>Ref.</th><th>Description</th><th>Qty</th><th>Unit</th><th>Unit Price</th><th>Total Amount</th></tr></thead><tbody>
      @foreach($packageEntries as $entry)<tr><td>{{ $entry->item_reference ?: '—' }}</td><td>{{ $entry->item_description }}</td><td class="amt">{{ $entry->quantity ?? 1 }}</td><td>{{ strtoupper($entry->unit ?? 'LS') }}</td><td class="amt">{{ $money::format($entry->unit_cost ?? 0) }}</td><td class="amt">{{ $money::format($lineTotal($entry)) }}</td></tr>@endforeach
      </tbody><tfoot><tr><th colspan="5">{{ $packageCode }} Subtotal</th><th class="amt">{{ $money::format(\App\Support\Money::sum($packageEntries->map($lineTotal))) }}</th></tr></tfoot></table></div></article>
    @endforeach
  @endforeach
  </div>
@empty<div class="phase-empty">No items have been added to this budget.</div>@endforelse
<footer class="budget-total"><span>{{ $budget->phase?->stage_label }} · {{ $categoryLabel }} Budget Total</span><strong>TSh {{ $money::format($budget->total_amount) }}</strong></footer>
</section>
<style>.budget-area{padding:5px 0 12px}.budget-area h3{font-size:16px;margin:10px 0}.budget-area h4{font-size:13px;color:var(--ink-soft);margin:14px 0 8px}.budget-package{margin:8px 0 16px}.package-title{padding:10px 12px;background:var(--primary-soft);border:1px solid var(--line);border-bottom:0;border-radius:8px 8px 0 0}.budget-package .tablewrap{border-radius:0 0 8px 8px}.budget-total{display:flex;justify-content:space-between;gap:15px;padding:15px;margin-top:15px;background:var(--surface-2);border-top:2px solid var(--line-strong);font-size:15px}</style>
@endsection
