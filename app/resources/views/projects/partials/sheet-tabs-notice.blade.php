@can('create', \App\Models\Project::class)
  @php($waitingTabs = \App\Models\SheetTabRequest::query()->where('status', 'pending')->count())
  @if($waitingTabs > 0)
    <a href="{{ route('admin.sheet-tabs.index') }}" style="display:block;background:var(--warn-soft);color:var(--warn-ink);border:1px solid var(--warn-line);border-radius:8px;padding:11px 15px;font-size:13px;font-weight:500;margin-bottom:18px;text-decoration:none">
      {{ $waitingTabs }} new {{ \Illuminate\Support\Str::plural('project', $waitingTabs) }} added in the Google Sheet {{ $waitingTabs === 1 ? 'is' : 'are' }} waiting for your approval. Review →
    </a>
  @endif
@endcan
