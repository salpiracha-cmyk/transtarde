(() => {
  'use strict';

  if (window.TT_ACCOUNT_RUNTIME?.observerCoalescing) return;

  const NativeMutationObserver = window.MutationObserver;
  const COALESCE_MS = 80;
  const runtime = window.TT_ACCOUNT_RUNTIME = {
    observerCoalescing: true,
    observerCount: 0,
    callbackCount: 0,
    lastCallbackAt: 0
  };

  class CoalescedMutationObserver {
    constructor(callback) {
      if (typeof callback !== 'function') {
        throw new TypeError('MutationObserver callback must be a function');
      }
      runtime.observerCount += 1;
      this.callback = callback;
      this.records = [];
      this.timer = 0;
      this.native = new NativeMutationObserver((records) => {
        this.records.push(...records);
        if (this.timer) return;
        this.timer = window.setTimeout(() => {
          this.timer = 0;
          const pending = this.records.splice(0);
          if (!pending.length) return;
          runtime.callbackCount += 1;
          runtime.lastCallbackAt = Date.now();
          this.callback(pending, this);
        }, COALESCE_MS);
      });
    }

    observe(target, options) {
      return this.native.observe(target, options);
    }

    disconnect() {
      if (this.timer) window.clearTimeout(this.timer);
      this.timer = 0;
      this.records.length = 0;
      return this.native.disconnect();
    }

    takeRecords() {
      return this.records.splice(0).concat(this.native.takeRecords());
    }
  }

  window.MutationObserver = CoalescedMutationObserver;

  const settleAccountsHome = () => {
    const home = document.querySelector('#entityHome');
    if (!home) return;
    document.querySelectorAll('.workspace').forEach(workspace => workspace.classList.remove('active'));
    home.style.display = 'block';
  };

  document.addEventListener('click', event => {
    if (!event.target.closest?.('[data-back]')) return;
    settleAccountsHome();
  }, true);
})();
