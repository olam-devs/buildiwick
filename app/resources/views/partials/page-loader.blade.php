{{-- Buildiwick loading screen shown while the next page loads (links and forms that take more than a moment).
     Downloads, new tabs and same-page links never show it. window.bwLoading() shows it from scripts. --}}
<div class="bw-loader" id="bwLoader" aria-hidden="true">
  <div class="bw-loader-bar"></div>
  <div class="bw-loader-box">
    <img src="{{ asset('images/buildiwick-mark.png') }}" alt="" class="bw-loader-mark">
    <div class="bw-loader-dots"><span></span><span></span><span></span></div>
  </div>
</div>
<style>
  .bw-loader{position:fixed;inset:0;z-index:200;display:flex;align-items:center;justify-content:center;background:color-mix(in srgb, var(--bg) 82%, transparent);backdrop-filter:blur(2px);opacity:0;pointer-events:none;transition:opacity .18s ease}
  .bw-loader.on{opacity:1;pointer-events:auto}
  .bw-loader-box{display:flex;flex-direction:column;align-items:center;gap:14px}
  .bw-loader-mark{width:84px;height:auto;animation:bw-pulse 1.3s ease-in-out infinite}
  .bw-loader-dots{display:flex;gap:6px}
  .bw-loader-dots span{width:7px;height:7px;border-radius:50%;background:#0081C3;animation:bw-dot 1.2s ease-in-out infinite}
  .bw-loader-dots span:nth-child(2){animation-delay:.15s}.bw-loader-dots span:nth-child(3){animation-delay:.3s}
  .bw-loader-bar{position:fixed;top:0;left:0;height:3px;width:40%;background:linear-gradient(90deg,transparent,#0081C3,transparent);animation:bw-bar 1.1s ease-in-out infinite}
  @keyframes bw-pulse{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(.9);opacity:.7}}
  @keyframes bw-dot{0%,100%{opacity:.25;transform:translateY(0)}50%{opacity:1;transform:translateY(-4px)}}
  @keyframes bw-bar{0%{transform:translateX(-100%)}100%{transform:translateX(260%)}}
  @media (prefers-reduced-motion: reduce){.bw-loader-mark,.bw-loader-dots span,.bw-loader-bar{animation:none}}
</style>
<script>
  (function () {
    var loader = document.getElementById('bwLoader'), timer = null;
    var show = function () { clearTimeout(timer); timer = setTimeout(function () { loader.classList.add('on'); }, 150); };
    var hide = function () { clearTimeout(timer); loader.classList.remove('on'); };
    window.bwLoading = show;
    var isDownload = function (url) { return /\.xlsx(\?|$)|\/export(\?|$|\/)/.test(url); };
    document.addEventListener('click', function (e) {
      var a = e.target.closest && e.target.closest('a[href]');
      if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
      var href = a.getAttribute('href') || '';
      if (a.target === '_blank' || a.hasAttribute('download') || href.charAt(0) === '#' || href.indexOf('javascript:') === 0 || isDownload(a.href)) { return; }
      if (a.origin !== location.origin) { return; }
      if (a.pathname === location.pathname && a.search === location.search && a.hash) { return; }
      show();
    });
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (e.defaultPrevented || form.target === '_blank' || isDownload(form.action || '')) { return; }
      show();
    });
    // Filters submit themselves on change; their pages are quick, but show the loader if they are not.
    window.addEventListener('pageshow', hide);
    window.addEventListener('pagehide', hide);
  })();
</script>
