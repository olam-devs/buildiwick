{{-- $rules: effective rules; $action: form URL; $project: optional, for per-project overrides --}}
<form method="POST" action="{{ $action }}">@csrf
  <div class="tablewrap"><table class="rules-table" style="min-width:640px"><thead><tr><th>Warn when…</th><th>Limit</th><th>On</th>@isset($project)<th>Source</th>@endisset</tr></thead><tbody>
  @foreach (\App\Services\ProjectWarningService::RULES as $code => $definition)
    <tr>
      <td>{{ $definition['label'] }}</td>
      <td><input type="number" name="rules[{{ $code }}][threshold]" min="0" step="0.01" value="{{ (float) $rules[$code]['threshold'] }}" @cannot('manage-director-strategy') disabled @endcannot> {{ $definition['unit'] }}</td>
      <td><input type="hidden" name="rules[{{ $code }}][active]" value="0"><input type="checkbox" name="rules[{{ $code }}][active]" value="1" @checked($rules[$code]['active']) @cannot('manage-director-strategy') disabled @endcannot></td>
      @isset($project)<td>{{ $rules[$code]['source'] === 'project' ? 'This project' : 'Company default' }}</td>@endisset
    </tr>
  @endforeach
  </tbody></table></div>
  @can('manage-director-strategy')<button class="btn btn-primary" type="submit" style="margin-top:10px">Save Warning Rules</button>@endcan
</form>
