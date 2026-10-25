<style>
.kpi-bar{height:8px;background:var(--surface-2);border-radius:999px;overflow:hidden;min-width:90px}
.kpi-fill{display:block;height:100%;border-radius:999px;background:var(--ink-dim)}
.kpi-fill.good{background:var(--good)}.kpi-fill.warn{background:var(--warn)}.kpi-fill.bad{background:var(--danger)}
.kpi-status{font-weight:600;font-size:12px}.kpi-status.good{color:#1c7a4f}.kpi-status.warn{color:#9a6400}.kpi-status.bad{color:#b42318}.kpi-status.flat{color:var(--ink-dim)}
.kpi-note{display:block;color:var(--ink-dim);font-size:11px;margin-top:3px}
.kpi-remove{background:none;border:0;color:#b42318;cursor:pointer;padding:0}
.warning-list{display:grid;gap:8px}.warning-item{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid #f2c4bc;background:var(--danger-soft);border-radius:8px}
.warning-item strong{display:block}.warning-item span{color:var(--ink-soft)}
.rules-table input[type=number]{width:140px}
</style>
