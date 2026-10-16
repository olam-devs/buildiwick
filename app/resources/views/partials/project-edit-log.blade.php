@if($editLogs->isNotEmpty())
<section class="panel form-section {{ $editLogSectionClass ?? '' }}">
  <h3>{{ $editLogTitle ?? 'Project Edit Log' }}</h3>
  <p class="form-context">Every change is retained with its justification and the person who made it.</p>
  <div class="tablewrap"><table><thead><tr><th>Date</th><th>Document</th><th>Change</th><th>Item</th><th>Changed Details</th><th>Justification</th><th>Edited By</th></tr></thead><tbody>
  @foreach($editLogs as $log)<tr>
    <td>{{ $log->created_at->format('d M Y H:i') }}</td>
    <td>{{ $log->document_code }}@if($log->to_version) · v{{ $log->to_version }}@endif</td>
    <td>{{ match($log->action) {'edit_started'=>'Edit started','entry_added'=>'Line added','entry_updated'=>'Line updated','entry_deleted'=>'Line deleted','returned_for_editing'=>'Returned for editing',default=>ucwords(str_replace('_',' ',$log->action))} }}</td>
    <td>{{ $log->item_description ?: '—' }}</td>
    <td>{{ $log->changeSummary() }}</td>
    <td>{{ $log->reason }}</td>
    <td>{{ $log->editor?->name ?? '—' }}</td>
  </tr>@endforeach
  </tbody></table></div>
</section>
@endif
