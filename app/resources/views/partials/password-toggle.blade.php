{{-- Adds a Show / Hide button inside every password field on the page. Include inside <head>. --}}
<style>
  .pw-wrap{position:relative;display:block}
  .pw-wrap input{padding-right:64px!important}
  .pw-toggle{position:absolute;top:50%;right:6px;transform:translateY(-50%);border:0;background:transparent;color:var(--primary-dark);font:inherit;font-size:12px;font-weight:600;padding:4px 8px;border-radius:6px;cursor:pointer}
  .pw-toggle:hover{background:var(--primary-soft)}
  .pw-toggle:focus-visible{outline:2px solid var(--primary);outline-offset:1px}
</style>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('input[type="password"]').forEach(function (input) {
      var wrap = document.createElement('span');
      wrap.className = 'pw-wrap';
      // Keep the field's own bottom spacing on the wrapper so the button stays centred on the input.
      var margin = getComputedStyle(input).marginBottom;
      if (margin && margin !== '0px') { wrap.style.marginBottom = margin; input.style.marginBottom = '0'; }
      input.parentNode.insertBefore(wrap, input);
      wrap.appendChild(input);
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'pw-toggle';
      button.textContent = 'Show';
      button.setAttribute('aria-label', 'Show password');
      button.setAttribute('aria-pressed', 'false');
      button.addEventListener('click', function () {
        var showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';
        button.textContent = showing ? 'Show' : 'Hide';
        button.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
        button.setAttribute('aria-pressed', showing ? 'false' : 'true');
        input.focus();
      });
      wrap.appendChild(button);
    });
  });
</script>
