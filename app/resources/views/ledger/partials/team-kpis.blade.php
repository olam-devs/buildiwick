{{-- Project KPI targets the Director chose to share with the project's PMs and Accountants (current periods only). --}}
@php
  $today = \Carbon\CarbonImmutable::today();
  $sharedTargets = \App\Models\DirectorKpiTarget::query()->where('project_id', $project->id)->where('visible_to_team', true)
      ->whereDate('period_start', '<=', $today)->whereDate('period_end', '>=', $today)->with('phase')->orderBy('metric')->get();
@endphp
@if($sharedTargets->isNotEmpty())
  @php
    $teamKpis = app(\App\Services\DirectorKpiService::class)->evaluate($sharedTargets);
  @endphp
  <section class="lg-panel" style="margin-bottom:14px">
    <h3>Targets for this project</h3><p class="hint">Set by the Director for the project team</p>
    <div class="lg-kpis">
      @foreach($teamKpis as $row)
        @php
          $target = $row['target'];
          $isPercent = ($row['definition']['unit'] ?? null) === 'percent';
          $show = fn ($value) => $value === null ? '—' : ($isPercent ? \App\Support\Money::format($value).'%' : 'TSh '.\App\Support\Money::format($value));
          $tone = ['met' => 'good', 'on_track' => 'good', 'within' => 'good', 'behind' => 'warn', 'over' => 'bad', 'missed' => 'bad'][$row['status']] ?? 'flat';
        @endphp
        <div class="lg-kpi {{ $tone }}">
          <div class="t">{{ $row['definition']['label'] ?? $target->metric }}</div>
          @if($target->phase || $target->main_category)<div class="sub">{{ collect([$target->phase?->stage_label, \App\Services\LedgerAnalyticsService::CATEGORIES[$target->main_category] ?? null])->filter()->join(' › ') }}</div>@endif
          <div class="v">{{ $show($row['actual']) }} <small>of {{ $show((string) $target->target_value) }}</small></div>
          <div class="lg-meter"><span class="{{ $tone === 'bad' ? 'over' : ($tone === 'warn' ? 'near' : '') }}" style="width:{{ $row['progress'] === null ? 0 : min(100, (float) $row['progress']) }}%"></span></div>
          <div class="sub">{{ \App\Services\DirectorKpiService::STATUS_LABELS[$row['status']] ?? $row['status'] }} · until {{ $target->period_end->format('d M Y') }}</div>
          @if($target->notes)<div class="sub">{{ $target->notes }}</div>@endif
        </div>
      @endforeach
    </div>
  </section>
@endif
