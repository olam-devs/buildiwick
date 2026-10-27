{{-- $groups: Current / Upcoming / Past evaluated targets --}}
@foreach ($groups as $title => $rows)
<section class="panel form-section">
  <h3>{{ $title }} targets</h3>
  <div class="tablewrap"><table style="min-width:820px"><thead><tr><th>Measure</th><th>Period</th><th class="amt">Target</th><th class="amt">Actual</th><th>Progress</th><th>Status</th>@can('manage-director-strategy')<th></th>@endcan</tr></thead><tbody>
  @forelse ($rows as $row)
    @include('director.partials.kpi-row', ['row' => $row, 'showDelete' => true])
  @empty
    <tr><td colspan="7">No {{ strtolower($title) }} targets.</td></tr>
  @endforelse
  </tbody></table></div>
</section>
@endforeach
