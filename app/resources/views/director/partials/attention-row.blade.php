{{-- One project's warnings. Only warnings with something to act on are links: lines awaiting confirmation open
     that project's Expenditure filtered to "to confirm", oldest first. The others are information. --}}
@php
  $warningsShown = isset($limit) ? array_slice($row['warnings'], 0, $limit) : $row['warnings'];
  $more = count($row['warnings']) - count($warningsShown);
@endphp
<div class="att-row">
  <a href="{{ route('director.projects.warnings', $row['project']) }}" class="att-project">{{ $row['project']->name }}</a> <span class="badge warn">{{ count($row['warnings']) }}</span>
  <ul>
    @foreach($warningsShown as $warning)
      @if($warning['rule'] === 'to_confirm_days')
        <li><a href="{{ route('ledger.expenses', [$row['project'], 'status' => 'to_confirm', 'sort' => 'oldest']) }}">{{ $warning['message'] }}</a></li>
      @else
        <li>{{ $warning['message'] }}</li>
      @endif
    @endforeach
    @if($more > 0)<li><a href="{{ route('director.projects.warnings', $row['project']) }}">{{ $more }} more for this project →</a></li>@endif
  </ul>
</div>
