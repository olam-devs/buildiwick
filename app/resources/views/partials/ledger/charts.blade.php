{{-- Interactive charts (no library): hover/tap tooltips and a page-wide category filter. --}}
<style>
  .lg-tip{position:fixed;z-index:80;pointer-events:none;background:var(--tip);border:1px solid var(--line-strong);color:#fff;font-size:12px;padding:7px 9px;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,.18);max-width:260px;line-height:1.45;opacity:0;transition:opacity .08s}
  .lg-tip.on{opacity:1}.lg-tip b{font-variant-numeric:tabular-nums}
  .lg-chart svg [data-tip]{cursor:pointer;transition:opacity .12s}
  .lg-chart svg:hover [data-tip]{opacity:.55}.lg-chart svg [data-tip]:hover{opacity:1}
  .lg-catbar{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
  .lg-catbar button{display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border-radius:99px;border:1px solid var(--line);background:var(--surface);font:inherit;font-size:12px;color:var(--ink-soft);cursor:pointer}
  .lg-catbar button.on{background:var(--primary-soft);border-color:var(--primary);color:var(--primary-dark);font-weight:600}
  .lg-catbar i{width:8px;height:8px;border-radius:50%;display:block}
  .lg-range{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
  .lg-range input{padding:6px 8px;border:1px solid var(--line);border-radius:8px;font:inherit;font-size:12px}
  .lg-empty-chart{padding:30px;text-align:center;color:var(--ink-dim);font-size:12.5px}
</style>
<div class="lg-tip" id="lgTip"></div>
<script>
window.LedgerCharts = (() => {
  const NS = 'http://www.w3.org/2000/svg';
  const fmt = v => Number(v).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const short = v => v >= 1e9 ? (v / 1e9).toFixed(1) + 'B' : v >= 1e6 ? (v / 1e6).toFixed(1) + 'M' : v >= 1e3 ? Math.round(v / 1e3) + 'K' : String(Math.round(v));
  const el = (name, attrs = {}) => { const e = document.createElementNS(NS, name); Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v)); return e; };
  const tip = document.getElementById('lgTip');
  const show = (html, x, y) => { tip.innerHTML = html; tip.classList.add('on'); const w = tip.offsetWidth, h = tip.offsetHeight; tip.style.left = Math.min(window.innerWidth - w - 8, x + 12) + 'px'; tip.style.top = Math.max(8, y - h - 12) + 'px'; };
  const hide = () => tip.classList.remove('on');
  document.addEventListener('mousemove', e => { const t = e.target.closest?.('[data-tip]'); t ? show(t.dataset.tip, e.clientX, e.clientY) : hide(); });
  document.addEventListener('touchstart', e => { const t = e.target.closest?.('[data-tip]'); if (t) { const p = e.touches[0]; show(t.dataset.tip, p.clientX, p.clientY); } else hide(); }, {passive: true});

  /** Stacked monthly columns. months: [{label,total,categories:{code:amount}}] */
  const monthly = (host, months, cats, selected) => {
    host.innerHTML = '';
    const codes = Object.keys(cats).filter(c => !selected || c === selected);
    const totals = months.map(m => codes.reduce((s, c) => s + Number(m.categories[c] || 0), 0));
    const max = Math.max(1, ...totals);
    if (totals.every(t => t === 0)) { host.innerHTML = '<div class="lg-empty-chart">Nothing recorded in this period.</div>'; return; }
    const W = 640, H = 210, pad = 34, bottom = 22, slot = (W - pad) / months.length, bw = Math.max(6, Math.min(34, slot * .62));
    const svg = el('svg', {viewBox: `0 0 ${W} ${H}`, width: '100%', role: 'img'}); svg.style.maxHeight = '240px';
    [.25, .5, .75, 1].forEach(s => { const y = (H - bottom) - (H - bottom - 10) * s; svg.append(el('line', {x1: pad, x2: W, y1: y, y2: y, stroke: '#EEF1F4'})); const t = el('text', {x: 0, y: y + 3, 'font-size': 9, fill: '#8D99A6'}); t.textContent = short(max * s); svg.append(t); });
    months.forEach((m, i) => {
      const x = pad + slot * i + (slot - bw) / 2; let base = H - bottom;
      const lines = codes.filter(c => Number(m.categories[c]) > 0).map(c => `<br><i style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${cats[c].color}"></i> ${cats[c].label}: <b>${fmt(m.categories[c])}</b>`).join('');
      codes.forEach(c => { const v = Number(m.categories[c] || 0); if (v <= 0) return; const h = (H - bottom - 10) * v / max;
        svg.append(el('rect', {x, y: base - h, width: bw, height: h, rx: 2, fill: cats[c].color, 'data-tip': `<b>${m.label}</b> · TSh ${fmt(totals[i])}${lines}`})); base -= h; });
      const t = el('text', {x: x + bw / 2, y: H - 6, 'text-anchor': 'middle', 'font-size': 9, fill: '#8D99A6'}); t.textContent = m.label; svg.append(t);
    });
    host.append(svg);
  };

  /** Donut of category shares. */
  const donut = (host, cats, values, selected, centerSub) => {
    host.innerHTML = '';
    const total = Object.keys(cats).reduce((s, c) => s + Number(values[c] || 0), 0);
    const svg = el('svg', {viewBox: '0 0 140 140', width: 150, height: 150});
    svg.append(el('circle', {cx: 70, cy: 70, r: 54, fill: 'none', stroke: '#EEF1F4', 'stroke-width': 16}));
    const C = 2 * Math.PI * 54; let off = 0;
    Object.keys(cats).forEach(c => { const v = Number(values[c] || 0); if (v <= 0 || total <= 0) return; const len = C * v / total;
      const seg = el('circle', {cx: 70, cy: 70, r: 54, fill: 'none', stroke: cats[c].color, 'stroke-width': selected && selected !== c ? 10 : 16,
        'stroke-dasharray': `${len} ${C - len}`, 'stroke-dashoffset': -off, transform: 'rotate(-90 70 70)', opacity: selected && selected !== c ? .35 : 1,
        'data-tip': `<b>${cats[c].label}</b><br>TSh ${fmt(v)} · ${(v / total * 100).toFixed(1)}%`});
      svg.append(seg); off += len; });
    const big = el('text', {x: 70, y: 66, 'text-anchor': 'middle', 'font-size': 13, 'font-weight': 700, fill: '#1F272E'});
    const focus = selected ? Number(values[selected] || 0) : total; big.textContent = short(focus);
    const small = el('text', {x: 70, y: 83, 'text-anchor': 'middle', 'font-size': 9, fill: '#8D99A6'}); small.textContent = selected ? cats[selected].label.split(' ')[0] : (centerSub || 'TSh total');
    svg.append(big, small); host.append(svg);
  };

  /** Horizontal bars with optional budget marker. rows: [{label, sub, value, budget, href}] */
  const bars = (host, rows, color, emptyText) => {
    host.innerHTML = '';
    const list = rows.filter(r => Number(r.value) > 0 || Number(r.budget || 0) > 0);
    if (!list.length) { host.innerHTML = `<div class="lg-empty-chart">${emptyText || 'Nothing to show.'}</div>`; return; }
    const max = Math.max(1, ...list.map(r => Math.max(Number(r.value), Number(r.budget || 0))));
    list.forEach(r => {
      const v = Number(r.value), b = Number(r.budget || 0), used = b > 0 ? (v / b * 100) : null;
      const row = document.createElement('div'); row.className = 'lg-bar';
      const tipText = `<b>${r.label}</b><br>TSh ${fmt(v)}${b > 0 ? `<br>Budget TSh ${fmt(b)} · ${used.toFixed(1)}% used` : ''}${r.sub ? `<br>${r.sub}` : ''}`;
      row.innerHTML = `<${r.href ? 'a href="' + r.href + '"' : 'span'} style="color:inherit;text-decoration:none">${r.label}<small>${r.sub || ''}</small></${r.href ? 'a' : 'span'}>
        <div class="track" data-tip="${tipText.replace(/"/g, '&quot;')}"><span class="fill" style="width:${v / max * 100}%;background:${used !== null && used > 100 ? 'var(--danger)' : color}"></span>${b > 0 ? `<span style="position:absolute;top:-2px;bottom:-2px;width:2px;background:#5D6A73;left:${Math.min(99.5, b / max * 100)}%"></span>` : ''}</div>
        <span class="amt">${fmt(v)}${b > 0 ? `<small>of ${fmt(b)}</small>` : ''}</span>`;
      host.append(row);
    });
  };

  /** Category chips that call onChange(code|null). */
  const categoryBar = (host, cats, onChange) => {
    const make = (code, label, color) => { const b = document.createElement('button'); b.type = 'button'; b.dataset.code = code || ''; b.innerHTML = (color ? `<i style="background:${color}"></i>` : '') + label; return b; };
    const buttons = [make(null, 'All categories'), ...Object.entries(cats).map(([c, v]) => make(c, v.label, v.color))];
    buttons.forEach(b => { host.append(b); b.addEventListener('click', () => { buttons.forEach(x => x.classList.toggle('on', x === b)); onChange(b.dataset.code || null); }); });
    buttons[0].classList.add('on');
  };

  return {fmt, short, monthly, donut, bars, categoryBar};
})();
</script>
