{{-- Light / dark theme. Colours are tokens; "Auto" follows the device, Light or Dark is remembered per browser
     (localStorage "bw-theme") and applied before the page paints. Include inside <head>. --}}
<script>
  (function () {
    try {
      var theme = localStorage.getItem('bw-theme');
      if (theme === 'light' || theme === 'dark') { document.documentElement.setAttribute('data-theme', theme); }
    } catch (e) {}
  })();
</script>
<style>
  :root{
    color-scheme: light;
    --bg:#F4F5F6; --surface:#FFFFFF; --surface-2:#F8F9FA;
    --ink:#1F272E; --ink-dim:#8D99A6; --ink-soft:#495764;
    --primary:#2490EF; --primary-dark:#1B75C4; --primary-soft:#EAF4FE;
    --line:#E1E5E8; --line-strong:#D1D8DD;
    --good:#2FA36E; --good-soft:#E5F6EE; --good-ink:#1c7a4f;
    --warn:#F5A623; --warn-soft:#FEF3E0; --warn-ink:#96660f; --warn-line:#f0c27a;
    --danger:#E6533C; --danger-soft:#FBE9E6; --danger-ink:#a3392a; --danger-line:#f2c4bc;
    --violet-soft:#e9e7ff; --violet-ink:#5342a6;
    --head:#27333D; --head-ink:#FFFFFF; --zebra:#FAFBFC; --tip:#1F272E;
    --chart-grid:#EEF1F4; --chart-label:#8D99A6;
    --input-bg:#FFFFFF; --scrim:rgba(20,30,40,.35);
    --row-labour:#F3FBF6; --row-logistics:#FFFAF0; --row-prelims:#F7F4FE;
  }
  @media (prefers-color-scheme: dark){
    :root:not([data-theme="light"]){
      color-scheme: dark;
      --bg:#0F1419; --surface:#171D24; --surface-2:#1C232B;
      --ink:#E6EAEE; --ink-dim:#8B97A3; --ink-soft:#B4BEC8;
      --primary:#3A9BF2; --primary-dark:#7CBCF7; --primary-soft:#14283C;
      --line:#2A333D; --line-strong:#36414C;
      --good:#34B57A; --good-soft:#12291F; --good-ink:#6FD3A3;
      --warn:#F5A623; --warn-soft:#2E2414; --warn-ink:#F2BE6A; --warn-line:#5C4520;
      --danger:#F06A55; --danger-soft:#33191A; --danger-ink:#FF9C8A; --danger-line:#5A2A26;
      --violet-soft:#25203F; --violet-ink:#B7A8FF;
      --head:#0B1015; --head-ink:#E6EAEE; --zebra:#1A2028; --tip:#0B1015;
      --chart-grid:#2A333D; --chart-label:#8B97A3;
      --input-bg:#11171D; --scrim:rgba(0,0,0,.55);
      --row-labour:#132219; --row-logistics:#2A2214; --row-prelims:#1E1A2E;
    }
  }
  :root[data-theme="dark"]{
    color-scheme: dark;
    --bg:#0F1419; --surface:#171D24; --surface-2:#1C232B;
    --ink:#E6EAEE; --ink-dim:#8B97A3; --ink-soft:#B4BEC8;
    --primary:#3A9BF2; --primary-dark:#7CBCF7; --primary-soft:#14283C;
    --line:#2A333D; --line-strong:#36414C;
    --good:#34B57A; --good-soft:#12291F; --good-ink:#6FD3A3;
    --warn:#F5A623; --warn-soft:#2E2414; --warn-ink:#F2BE6A; --warn-line:#5C4520;
    --danger:#F06A55; --danger-soft:#33191A; --danger-ink:#FF9C8A; --danger-line:#5A2A26;
    --violet-soft:#25203F; --violet-ink:#B7A8FF;
    --head:#0B1015; --head-ink:#E6EAEE; --zebra:#1A2028; --tip:#0B1015;
    --chart-grid:#2A333D; --chart-label:#8B97A3;
    --input-bg:#11171D; --scrim:rgba(0,0,0,.55);
    --row-labour:#132219; --row-logistics:#2A2214; --row-prelims:#1E1A2E;
  }
  /* Chart SVGs carry fixed colours as attributes; CSS takes precedence, so they follow the theme. */
  svg [stroke="#EEF1F4"]{stroke:var(--chart-grid)}
  svg [fill="#8D99A6"]{fill:var(--chart-label)}
  svg [fill="#1F272E"]{fill:var(--ink)}
  select option, select optgroup{background:var(--surface);color:var(--ink)}
  .access-choice{display:grid;grid-template-columns:1fr 1fr;gap:8px}
  .access-choice .access-option{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid var(--line-strong);border-radius:10px;background:var(--surface);cursor:pointer;margin:0}
  .access-option input{width:16px!important;height:16px;margin-top:2px;accent-color:var(--primary);flex:none}
  .access-option b{display:block;font-size:13px;color:var(--ink)}.access-option small{display:block;font-size:11.5px;color:var(--ink-dim);font-weight:400}
  .access-option.on-active:has(input:checked){border-color:var(--good);background:var(--good-soft)}.access-option.on-active:has(input:checked) b{color:var(--good-ink)}
  .access-option.on-inactive:has(input:checked){border-color:var(--danger);background:var(--danger-soft)}.access-option.on-inactive:has(input:checked) b{color:var(--danger-ink)}
  .theme-switch.compact{padding:2px;border-radius:99px;background:var(--surface)}
  .theme-switch.compact button{padding:5px 10px;border-radius:99px;font-size:12px;white-space:nowrap;display:inline-flex;align-items:center;gap:4px}
  .theme-switch button{white-space:nowrap}
  .theme-switch{display:flex;gap:2px;padding:3px;border:1px solid var(--line);border-radius:9px;background:var(--surface-2)}
  .theme-switch button{flex:1;border:0;background:transparent;color:var(--ink-dim);font:inherit;font-size:12px;font-weight:600;padding:5px 6px;border-radius:6px;cursor:pointer}
  .theme-switch button.on{background:var(--surface);color:var(--ink);box-shadow:0 1px 2px rgba(0,0,0,.12)}
</style>
<script>
  // Wires every .theme-switch on the page: Light / Dark / Auto (device setting).
  document.addEventListener('DOMContentLoaded', function () {
    var current = function () { try { return localStorage.getItem('bw-theme') || 'auto'; } catch (e) { return 'auto'; } };
    var apply = function (theme) {
      try { theme === 'auto' ? localStorage.removeItem('bw-theme') : localStorage.setItem('bw-theme', theme); } catch (e) {}
      if (theme === 'auto') { document.documentElement.removeAttribute('data-theme'); } else { document.documentElement.setAttribute('data-theme', theme); }
      document.querySelectorAll('.theme-switch button').forEach(function (b) { b.classList.toggle('on', b.dataset.theme === theme); });
    };
    document.querySelectorAll('.theme-switch button').forEach(function (b) {
      b.classList.toggle('on', b.dataset.theme === current());
      b.addEventListener('click', function () { apply(b.dataset.theme); });
    });
  });
</script>
