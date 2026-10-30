@php
  $money = \App\Support\Money::class;
  $target = $row['target'];
  $definition = $row['definition'];
  $isPercent = ($definition['unit'] ?? null) === 'percent';
  $show = fn ($value) => $value === null ? '—' : ($isPercent ? $money::format($value).'%' : 'TSh '.$money::format($value));
  $statusClass = ['met' => 'good', 'on_track' => 'good', 'within' => 'good', 'behind' => 'warn', 'over' => 'bad', 'missed' => 'bad', 'upcoming' => 'flat'][$row['status']] ?? 'flat';
  $bar = $row['progress'] === null ? 0 : min(100, (float) $row['progress']);
@endphp
<tr>
  <td>{{ $definition['label'] ?? $target->metric }}@if($target->phase || $target->main_category)<small class="kpi-note">{{ collect([$target->phase?->stage_label, \App\Services\LedgerAnalyticsService::CATEGORIES[$target->main_category] ?? null])->filter()->join(' › ') }}</small>@endif @if($target->notes)<small class="kpi-note">{{ $target->notes }}</small>@endif</td>
  @if($showProject ?? false)<td>{{ $target->project?->name ?? 'Whole company' }}</td>@endif
  <td>{{ $target->period_start->format('d M Y') }} – {{ $target->period_end->format('d M Y') }}</td>
  <td class="amt">{{ $show((string) $target->target_value) }}</td>
  <td class="amt">{{ $show($row['actual']) }}</td>
  <td><div class="kpi-bar"><span class="kpi-fill {{ $statusClass }}" style="width:{{ $bar }}%"></span></div><small class="kpi-note">{{ $row['progress'] === null ? '—' : $money::format($row['progress']).'%' }}</small></td>
  <td><span class="kpi-status {{ $statusClass }}">{{ \App\Services\DirectorKpiService::STATUS_LABELS[$row['status']] ?? $row['status'] }}</span></td>
  @if(($showDelete ?? false) && auth()->user()->can('manage-director-strategy'))<td style="white-space:nowrap">@if($target->project_id)<form method="POST" action="{{ route('director.kpis.visibility', $target) }}" style="display:inline">@csrf<button class="kpi-remove" type="submit" style="color:{{ $target->visible_to_team ? 'var(--good)' : 'var(--ink-dim)' }}" title="Shown to the project's PMs and Accountants?">{{ $target->visible_to_team ? 'Team sees it' : 'Hidden from team' }}</button></form> · @endif<form method="POST" action="{{ route('director.kpis.destroy', $target) }}" style="display:inline" onsubmit="return confirm('Remove this target?');">@csrf @method('DELETE')<button class="kpi-remove" type="submit">Remove</button></form></td>@endif
</tr>
