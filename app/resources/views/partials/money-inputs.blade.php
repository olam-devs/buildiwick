<script>
/*
 * Money fields (inputs marked data-money) show thousands commas while typing: 4500000 shows as 4,500,000.
 * Scripts still read and write plain numbers through .value, and commas are removed before a form is sent,
 * so calculations and the server only ever see plain numbers like 4500000.
 */
(() => {
  const native = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
  const plain = text => String(text ?? '').replace(/,/g, '');
  const withCommas = text => {
    const clean = plain(text).replace(/[^\d.]/g, '');
    const dot = clean.indexOf('.');
    const whole = (dot < 0 ? clean : clean.slice(0, dot)).replace(/^0+(?=\d)/, '');
    const cents = dot < 0 ? '' : '.' + clean.slice(dot + 1).replace(/\./g, '').slice(0, 2);
    return whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + cents;
  };
  const enhance = input => {
    if (input.dataset.moneyReady) { return; }
    input.dataset.moneyReady = '1';
    Object.defineProperty(input, 'value', {
      configurable: true,
      get() { return plain(native.get.call(this)); },
      set(value) { native.set.call(this, withCommas(value)); },
    });
    native.set.call(input, withCommas(native.get.call(input)));
    // Re-add commas as she types, keeping the cursor after the same digit.
    input.addEventListener('input', () => {
      const shown = native.get.call(input);
      const next = withCommas(shown);
      if (next === shown) { return; }
      const caret = input.selectionStart ?? shown.length;
      const digitsBefore = shown.slice(0, caret).replace(/[^\d.]/g, '').length;
      native.set.call(input, next);
      let position = 0;
      for (let seen = 0; position < next.length && seen < digitsBefore; position++) { if (/[\d.]/.test(next[position])) { seen++; } }
      input.setSelectionRange(position, position);
    }, true);
  };
  const scan = root => root.querySelectorAll('input[data-money]').forEach(enhance);
  scan(document);
  new MutationObserver(changes => changes.forEach(change => change.addedNodes.forEach(node => {
    if (node.nodeType !== 1) { return; }
    if (node.matches('input[data-money]')) { enhance(node); }
    scan(node);
  }))).observe(document.body, {childList: true, subtree: true});
  // Send plain numbers: strip commas from money fields whenever form data is built (normal submit or new FormData(form)).
  document.addEventListener('formdata', event => {
    const names = new Set([...event.target.elements].filter(el => el.matches('input[data-money]') && el.name).map(el => el.name));
    names.forEach(name => {
      const values = event.formData.getAll(name);
      event.formData.delete(name);
      values.forEach(value => event.formData.append(name, typeof value === 'string' ? plain(value) : value));
    });
  }, true);
  window.BuildiwickMoney = {plain, withCommas, enhance};
})();
</script>
