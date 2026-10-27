{{-- $action: where the target is saved (company or one project) --}}
<form method="POST" action="{{ $action }}" class="kpi-form">@csrf
  <div class="field"><label for="metric">Measure <span class="req">*</span></label><select id="metric" name="metric" required>@foreach(\App\Services\DirectorKpiService::METRICS as $code => $metric)<option value="{{ $code }}" @selected(old('metric') === $code)>{{ $metric['label'] }}</option>@endforeach</select></div>
  <div class="field"><label for="period">Period <span class="req">*</span></label><select id="period" name="period" required>@foreach(\App\Services\DirectorKpiService::PERIODS as $code => $label)<option value="{{ $code }}" @selected(old('period', 'this_quarter') === $code)>{{ $label }}</option>@endforeach</select></div>
  <div class="field"><label for="from">From (custom)</label><input type="date" id="from" name="from" value="{{ old('from') }}"></div>
  <div class="field"><label for="to">To (custom)</label><input type="date" id="to" name="to" value="{{ old('to') }}"></div>
  <div class="field"><label for="target_value">Target (TSh, or % for budget used) <span class="req">*</span></label><input id="target_value" name="target_value" type="number" min="0" step="0.01" value="{{ old('target_value') }}" required></div>
  <div class="field"><label for="notes">Notes</label><input id="notes" name="notes" maxlength="1000" value="{{ old('notes') }}"></div>
  <button class="btn btn-primary" type="submit">Save Target</button>
</form>
<style>.kpi-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;align-items:end}.kpi-form .field{margin:0}</style>
