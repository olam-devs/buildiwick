<style>
  .lg-head{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:18px}
  .lg-head h2{margin:0;font-size:21px;letter-spacing:-.01em}
  .lg-head p{margin:4px 0 0;color:var(--ink-dim);font-size:12.5px}
  .lg-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  .lg-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-bottom:16px}
  .lg-card{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:14px 16px;position:relative;overflow:hidden}
  .lg-card .k{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-dim);font-weight:600}
  .lg-card .v{font-size:22px;font-weight:700;margin-top:6px;letter-spacing:-.01em;font-variant-numeric:tabular-nums}
  .lg-card .s{font-size:11.5px;color:var(--ink-soft);margin-top:4px}
  .lg-card.accent{border-top:3px solid var(--primary)}
  .lg-card.warn{border-top:3px solid var(--warn)}
  .lg-card.good{border-top:3px solid var(--good)}
  .lg-card.danger{border-top:3px solid var(--danger)}
  a.lg-card{text-decoration:none;color:inherit}a.lg-card:hover{border-color:var(--primary)}
  .lg-meter{height:6px;background:var(--surface-2);border-radius:99px;overflow:hidden;margin-top:9px}
  .lg-meter span{display:block;height:100%;border-radius:99px;background:var(--primary)}
  .lg-meter span.over{background:var(--danger)}.lg-meter span.near{background:var(--warn)}
  .lg-grid{display:grid;grid-template-columns:repeat(12,1fr);gap:14px;margin-bottom:14px}
  .lg-panel{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:16px 18px;min-width:0}
  .lg-panel h3{margin:0 0 4px;font-size:14px}
  .lg-panel .hint{margin:0 0 12px;color:var(--ink-dim);font-size:11.5px}
  .span-4{grid-column:span 4}.span-5{grid-column:span 5}.span-6{grid-column:span 6}.span-7{grid-column:span 7}.span-8{grid-column:span 8}.span-12{grid-column:span 12}
  @media(max-width:1000px){.span-4,.span-5,.span-6,.span-7,.span-8{grid-column:span 12}}
  .lg-legend{display:grid;gap:8px;font-size:12px}
  .lg-legend div{display:grid;grid-template-columns:12px 1fr auto;gap:8px;align-items:center}
  .lg-legend i{width:10px;height:10px;border-radius:3px;display:block}
  .lg-legend b{font-variant-numeric:tabular-nums}
  .lg-donut-wrap{display:flex;gap:18px;align-items:center;flex-wrap:wrap}
  .lg-bars{display:grid;gap:10px}
  .lg-bar{display:grid;grid-template-columns:minmax(120px,1.2fr) 2fr auto;gap:10px;align-items:center;font-size:12.5px}
  .lg-bar .track{position:relative;height:10px;background:var(--surface-2);border-radius:99px;overflow:hidden}
  .lg-bar .fill{position:absolute;inset:0 auto 0 0;border-radius:99px}
  .lg-bar .amt{font-variant-numeric:tabular-nums;white-space:nowrap;text-align:right}
  .lg-bar small{display:block;color:var(--ink-dim);font-size:10.5px}
  .lg-filters{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:12px;margin-bottom:12px}
  .lg-filters .field{margin:0;min-width:140px}
  .lg-filters .field label{font-size:10.5px}
  .lg-chips{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}
  .lg-chip{display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:99px;border:1px solid var(--line);background:var(--surface);font-size:12px;text-decoration:none;color:var(--ink-soft)}
  .lg-chip.on{background:var(--primary-soft);border-color:var(--primary);color:var(--primary-dark);font-weight:600}
  .lg-chip i{width:8px;height:8px;border-radius:50%;display:block}
  .lg-table{width:100%;border-collapse:separate;border-spacing:0;font-size:12.5px}
  .lg-table th{position:sticky;top:0;background:#27333D;color:#fff;font-weight:600;font-size:11px;text-align:left;padding:8px 9px;white-space:nowrap;z-index:1}
  .lg-table td{padding:7px 9px;border-bottom:1px solid var(--line);vertical-align:top}
  .lg-table tbody tr:nth-child(even) td{background:#FAFBFC}
  .lg-table tbody tr:hover td{background:var(--primary-soft)}
  .lg-table .amt{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
  .lg-table tfoot td{font-weight:700;background:#EAF4FE;border-top:2px solid var(--primary)}
  .lg-table .code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11.5px;color:var(--ink-soft);white-space:nowrap}
  .lg-table .muted{color:var(--ink-dim);font-size:11px;display:block}
  .lg-scroll{overflow:auto;max-height:70vh;border:1px solid var(--line);border-radius:12px;background:var(--surface)}
  .lg-pill{display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600;white-space:nowrap}
  .lg-pill.paid{background:var(--good-soft);color:#1c7a4f}.lg-pill.unpaid{background:var(--warn-soft);color:#9a6400}.lg-pill.part_paid{background:var(--primary-soft);color:var(--primary-dark)}
  .lg-cat{display:inline-flex;align-items:center;gap:6px;white-space:nowrap}.lg-cat i{width:8px;height:8px;border-radius:50%;display:block}
  .lg-tree td.phase{white-space:nowrap}
  .lg-tree .d2{padding-left:26px}.lg-tree .d3{padding-left:48px}
  .lg-tree tr.top td{font-weight:600}
  .lg-row-actions{display:flex;gap:8px;align-items:center;white-space:nowrap}
  .lg-row-actions form{margin:0}
  .lg-link{background:none;border:0;padding:0;color:var(--primary-dark);cursor:pointer;font:inherit;font-size:12px;text-decoration:none}
  .lg-link.danger{color:#b42318}
  .lg-empty{padding:28px;text-align:center;color:var(--ink-dim)}
  .lg-form{max-width:880px}
  .lg-form .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}
  .lg-form .field{margin:0}
  .lg-step{display:flex;gap:10px;align-items:center;margin:16px 0 8px;font-weight:600;font-size:13px}
  .lg-step span{width:22px;height:22px;border-radius:50%;background:var(--primary);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:11px}
  .lg-cat-pick{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:8px}
  .lg-cat-pick label{border:1px solid var(--line);border-radius:10px;padding:10px 12px;display:flex;gap:8px;align-items:center;cursor:pointer;font-size:12.5px;font-weight:600;background:var(--surface)}
  .lg-cat-pick input{accent-color:var(--primary)}
  .lg-cat-pick label:has(input:checked){border-color:var(--primary);background:var(--primary-soft)}
  .lg-people{border:1px solid var(--line);border-radius:10px;max-width:520px;background:var(--surface)}
  .lg-people .lg-people-search{width:100%;border:0;border-bottom:1px solid var(--line);border-radius:10px 10px 0 0;padding:8px 10px;font:inherit;font-size:13px}
  .lg-people .list{max-height:220px;overflow-y:auto;padding:4px}
  .field .lg-people label,.lg-people label{display:flex;align-items:center;gap:8px;padding:6px 8px;margin:0;border-radius:6px;font-size:13px;font-weight:500;color:var(--ink);cursor:pointer;text-transform:none;letter-spacing:0}
  .lg-people label:hover{background:var(--surface-2,#F4F5F6)}
  .field .lg-people input[type=checkbox],.lg-people input[type=checkbox]{width:16px;height:16px;flex:0 0 16px;margin:0;padding:0;accent-color:var(--primary)}
  .lg-people label small{margin-left:auto;color:var(--ink-dim);font-size:11.5px}
  .lg-people .foot{border-top:1px solid var(--line);padding:6px 10px;font-size:12px;color:var(--ink-dim)}
  .lg-seg{display:inline-flex;border:1px solid var(--line);border-radius:10px;overflow:hidden}
  .lg-seg label{padding:8px 14px;font-size:12.5px;cursor:pointer;display:flex;gap:6px;align-items:center}
  .lg-seg label:has(input:checked){background:var(--primary-soft);color:var(--primary-dark);font-weight:600}
  .lg-seg input{display:none}
  dialog.lg-dialog{border:0;border-radius:14px;padding:0;max-width:440px;width:92vw;box-shadow:0 20px 50px rgba(0,0,0,.2)}
  dialog.lg-dialog::backdrop{background:rgba(20,30,40,.35)}
  .lg-dialog form{padding:18px 20px;display:grid;gap:10px}
  .lg-dialog h3{margin:0}
  .lg-pager{display:flex;gap:6px;justify-content:flex-end;margin-top:10px;font-size:12px;align-items:center}
  .lg-search{padding:8px 11px;border:1px solid var(--line);border-radius:8px;min-width:240px;font:inherit;font-size:13px;background:var(--surface)}
  .lg-select{padding:8px 10px;border:1px solid var(--line);border-radius:8px;font:inherit;font-size:13px;background:var(--surface)}
  .lg-search:focus,.lg-select:focus{outline:2px solid var(--primary-soft);border-color:var(--primary)}
  .lg-num{color:var(--ink-dim);font-weight:600;font-variant-numeric:tabular-nums}
  .lg-pill.denied{background:var(--danger-soft);color:#b42318}
  .lg-actions-col{flex-direction:column;align-items:flex-start;gap:3px}
  /* Phones and small tablets: tables marked lg-cards-sm become stacked cards with labels. */
  @media(max-width:760px){
    .lg-head{flex-direction:column;align-items:stretch}.lg-head h2{font-size:19px}
    .lg-actions{width:100%}.lg-actions .btn{flex:1;justify-content:center}.lg-search{min-width:0;flex:1 1 100%}
    .lg-cards{grid-template-columns:1fr 1fr;gap:8px}.lg-card{padding:11px 12px}.lg-card .v{font-size:16px}
    .lg-filters .field{min-width:calc(50% - 6px)!important;flex:1 1 calc(50% - 6px)}
    .lg-scroll{max-height:none;overflow-x:auto;border:0;background:none}
    .tablewrap{overflow-x:auto}
    .lg-cards-sm thead{display:none}
    .lg-cards-sm,.lg-cards-sm tbody,.lg-cards-sm tr,.lg-cards-sm td,.lg-cards-sm tfoot{display:block;width:100%;min-width:0}
    .lg-cards-sm tbody tr{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:10px 12px;margin-bottom:10px}
    .lg-cards-sm tbody tr td{border:0;padding:4px 0;background:none!important;display:flex;justify-content:space-between;gap:12px;text-align:right}
    .lg-cards-sm tbody tr td::before{content:attr(data-label);color:var(--ink-dim);font-size:11px;text-transform:uppercase;letter-spacing:.04em;text-align:left;flex:none}
    .lg-cards-sm tbody tr td:not([data-label]){justify-content:flex-end}
    .lg-cards-sm tbody tr td .muted{display:block}
    .lg-cards-sm tbody tr td > *{text-align:right}
    .lg-cards-sm tfoot td{border:0;background:var(--primary-soft);border-radius:10px;padding:8px 12px}
    .lg-actions-col{flex-direction:row;gap:14px}
    .lg-grid{grid-template-columns:1fr}
  }
</style>
