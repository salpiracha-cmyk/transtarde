(() => {
  'use strict';
  let generation = 0, owner = '';
  const claim = value => { owner = value; generation += 1; return generation; };
  document.addEventListener('click', event => {
    const icon = event.target.closest?.('[data-expense]');
    if (icon) claim(icon.dataset.expense);
    else if (event.target.closest?.('.entityBtn,[data-back],[data-editor-back],.tt-clean-close,.appCard[data-key]')) claim('');
  }, true);
  window.TT_EXPENSE_EDITOR = {
    claim,
    token: () => generation,
    current: (value, token) => owner === value && (token === undefined || token === generation)
  };
})();
