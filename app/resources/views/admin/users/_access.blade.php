{{-- What this user may do and which projects they see (recording mode). --}}
@php
  $current = $managedUser ? $managedUser->effectivePermissions() : null;
  $checkedPermissions = old('permissions', $current ?? []);
  $allProjects = (bool) old('all_projects', $managedUser?->all_projects ?? false);
  $selectedProjects = array_map('intval', old('project_ids', $assignedIds));
@endphp
<style>
  .acc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:8px;margin-top:6px}
  .acc-item{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:var(--surface);cursor:pointer}
  .acc-item input{margin-top:2px;width:16px;height:16px;accent-color:var(--primary);flex:none}
  .acc-item b{display:block;font-size:12.5px}.acc-item small{display:block;color:var(--ink-dim);font-size:11px;margin-top:2px}
  .acc-item:has(input:checked){border-color:var(--primary);background:var(--primary-soft)}
  .acc-projects{max-height:280px;overflow:auto;border:1px solid var(--line);border-radius:10px;margin-top:8px;padding:4px}
  .acc-projects label{display:flex;gap:9px;align-items:center;padding:7px 9px;border-radius:7px;font-size:12.5px;cursor:pointer}
  .acc-projects label:hover{background:var(--surface-2)}
  .acc-projects input{width:15px;height:15px;accent-color:var(--primary)}
  .acc-projects small{color:var(--ink-dim);margin-left:auto;font-size:10.5px}
  .acc-note{padding:10px 12px;border-radius:10px;background:var(--surface-2);font-size:12.5px;color:var(--ink-soft)}
</style>
<section class="form-section" id="accessSection" style="margin-top:18px">
  <h3 style="margin-bottom:4px">What this user can do</h3>
  <p class="form-context" style="margin-top:0">Ticked by default for the chosen role; change any box for this person.</p>
  <div class="acc-note" id="directorNote" hidden>Directors can do everything and see every project.</div>
  <div class="acc-grid" id="permissionGrid">
    @foreach(\App\Support\Access::PERMISSIONS as $code => $permission)
      <label class="acc-item"><input type="checkbox" name="permissions[]" value="{{ $code }}" @checked(in_array($code, $checkedPermissions, true))><span><b>{{ $permission['label'] }}</b><small>{{ $permission['hint'] }}</small></span></label>
    @endforeach
  </div>

  <h3 style="margin:18px 0 4px">Projects this user can open</h3>
  <div id="projectAccess">
    <label class="acc-item" style="max-width:420px"><input type="checkbox" name="all_projects" value="1" id="allProjects" @checked($allProjects)><span><b>All projects</b><small>Including projects created later</small></span></label>
    <div id="projectPicker" @if($allProjects) hidden @endif>
      <input type="search" id="projectSearch" placeholder="Search projects…" style="margin-top:10px;width:100%;max-width:420px;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
      <div class="acc-projects" id="projectList">
        @foreach($projects as $project)
          <label data-search="{{ strtolower($project->name.' '.$project->project_code) }}"><input type="checkbox" name="project_ids[]" value="{{ $project->id }}" @checked(in_array($project->id, $selectedProjects, true))>{{ $project->name }}<small>{{ $project->project_code }}</small></label>
        @endforeach
      </div>
      <p class="form-context"><span id="projectCount"></span> selected · <button type="button" class="lg-link" id="selectVisible" style="background:none;border:0;color:var(--primary-dark);cursor:pointer;padding:0">Select all shown</button> · <button type="button" id="clearProjects" style="background:none;border:0;color:var(--primary-dark);cursor:pointer;padding:0">Clear</button></p>
    </div>
  </div>
</section>
<script>
(() => {
  const defaults = @json($roleDefaults);
  const role = document.getElementById('role_id');
  const isNew = {{ $managedUser ? 'false' : 'true' }};
  const boxes = [...document.querySelectorAll('#permissionGrid input')];
  const all = document.getElementById('allProjects'), picker = document.getElementById('projectPicker');
  const list = [...document.querySelectorAll('#projectList label')];
  const count = () => document.getElementById('projectCount').textContent = list.filter(l => l.querySelector('input').checked).length;
  const applyRole = (reset) => {
    const d = defaults[role.value] || {permissions: [], all_projects: false, director: false};
    document.getElementById('directorNote').hidden = !d.director;
    document.getElementById('permissionGrid').hidden = d.director;
    document.getElementById('projectAccess').hidden = d.director;
    if (reset) { boxes.forEach(b => b.checked = d.permissions.includes(b.value)); all.checked = d.all_projects; picker.hidden = all.checked; }
  };
  role.addEventListener('change', () => applyRole(true));
  applyRole(isNew && !{{ old('role_id') ? 'true' : 'false' }});
  all.addEventListener('change', () => picker.hidden = all.checked);
  document.getElementById('projectSearch').addEventListener('input', e => { const q = e.target.value.toLowerCase(); list.forEach(l => l.hidden = q && !l.dataset.search.includes(q)); });
  document.getElementById('selectVisible').addEventListener('click', () => { list.filter(l => !l.hidden).forEach(l => l.querySelector('input').checked = true); count(); });
  document.getElementById('clearProjects').addEventListener('click', () => { list.forEach(l => l.querySelector('input').checked = false); count(); });
  list.forEach(l => l.querySelector('input').addEventListener('change', count)); count();
})();
</script>
