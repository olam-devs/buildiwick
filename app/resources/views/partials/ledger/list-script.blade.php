{{-- Live search (as you type) and clickable rows for a numbered list. Needs $table and $search element ids. --}}
<script>
(() => {
  const table = document.getElementById(@json($table)), search = document.getElementById(@json($search));
  if (!table) return;
  const rows = [...table.querySelectorAll('tbody tr[data-search]')];
  const renumber = () => { let n = 0; rows.forEach(r => { const cell = r.querySelector('.lg-num'); if (!r.hidden && cell) cell.textContent = ++n; }); };
  if (search) search.addEventListener('input', () => { const q = search.value.trim().toLowerCase(); rows.forEach(r => r.hidden = q !== '' && !r.dataset.search.includes(q)); renumber(); });
  rows.forEach(r => { if (!r.dataset.href) return; r.style.cursor = 'pointer'; r.addEventListener('click', e => { if (!e.target.closest('a,button,input,select,form')) location.href = r.dataset.href; }); });
})();
</script>
