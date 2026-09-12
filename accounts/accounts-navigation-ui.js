(() => {
  'use strict';

  if (window.TT_ACCOUNTS_NAVIGATION?.installed) return;

  const q = selector => document.querySelector(selector);
  const qa = selector => [...document.querySelectorAll(selector)];

  const style = document.createElement('style');
  style.id = 'ttAccountsNavigationStyle';
  style.textContent = `
    #entityHome .entityHero{align-items:center;margin-bottom:14px}
    #entityHome .entityHero>div:first-child>p:last-child{display:none}
    #entityHome .entityHero h1{font-size:25px;margin-bottom:0}
    #entityHome>.notice{padding:8px 12px;margin-bottom:12px;font-size:12px}
    #homeGrid{grid-template-columns:repeat(7,minmax(0,1fr));gap:12px}
    #homeGrid .appCard{min-height:112px;padding:13px 10px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;box-shadow:0 4px 15px rgba(19,40,65,.07)}
    #homeGrid .appCard:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(19,40,65,.11)}
    #homeGrid .appIcon{width:42px;height:42px;margin:0 0 9px;border-radius:12px;font-size:21px}
    #homeGrid .appCard h3{font-size:13px;margin:0;line-height:1.25}
    #homeGrid .appCard p{display:none}
    #entityHome>.panel{margin-top:14px;box-shadow:0 4px 15px rgba(19,40,65,.06)}
    #entityHome>.panel .panelHead{padding:12px 15px}
    #entityHome>.panel .summary{padding:12px;gap:10px}
    #entityHome>.panel .metric{padding:10px 12px}
    #entityHome>.panel .metric strong{font-size:17px}
    #ttvAttention.ttv-attn{display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:10px 14px!important;margin:0 0 12px!important;min-height:0}
    #ttvAttention h3{font-size:13px;margin:0!important}
    #ttvAttention .ttv-muted{font-size:11px}
    .entitySwitcher{display:none!important;position:fixed;top:60px;right:64px;z-index:90;width:min(610px,calc(100vw - 28px));padding:10px;background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 18px 45px rgba(12,32,54,.22);grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
    .entitySwitcher.tt-entity-menu-open{display:grid!important}
    .entitySwitcher .entityBtn{min-width:0;box-shadow:none;padding:10px 11px}
    .tt-editor-bar{display:flex;align-items:center;gap:14px;padding:0 0 14px}
    .tt-editor-bar h2{margin:0;font-size:20px}
    .tt-editor-bar .backBtn{flex:0 0 auto}
    #expenseEditor:not(.tt-editor-stage),#purchaseEditor:not(.tt-editor-stage){display:none!important}
    .workspace.tt-editor-open>.panelHead,.workspace.tt-editor-open>.subGrid{display:none!important}
    .workspace.tt-editor-open>.tt-editor-stage{display:block!important}
    .workspace.tt-editor-open>.tt-editor-stage>.split,.workspace.tt-editor-open>.tt-editor-stage>.tte{padding-top:0}
    @media(max-width:1250px){#homeGrid{grid-template-columns:repeat(5,minmax(0,1fr))}}
    @media(max-width:900px){#homeGrid{grid-template-columns:repeat(4,minmax(0,1fr))}}
    @media(max-width:700px){
      #homeGrid{grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}
      #homeGrid .appCard{min-height:100px}
      .entitySwitcher{right:10px;top:62px;grid-template-columns:1fr}
      .tt-editor-bar{align-items:flex-start;flex-direction:column;gap:9px}
    }
  `;
  document.head.appendChild(style);

  function closeEntityMenu() {
    q('.entitySwitcher')?.classList.remove('tt-entity-menu-open');
  }

  function setupDirectLanding() {
    const oldLanding = q('#ttEntityLanding');
    if (oldLanding) oldLanding.remove();

    const home = q('#entityHome');
    if (home && !q('.workspace.active')) home.style.display = 'block';

    const switcher = q('.entitySwitcher');
    const change = q('#ttChangeEntity');
    const topbar = q('.topbar');
    if (!switcher || !change || !topbar) return false;

    if (switcher.parentElement !== topbar) topbar.insertBefore(switcher, q('.power'));
    change.textContent = 'Change Company';
    change.setAttribute('aria-haspopup', 'menu');
    change.setAttribute('aria-expanded', 'false');

    if (!change.dataset.ttDirectLanding) {
      change.dataset.ttDirectLanding = '1';
      change.onclick = event => {
        event.preventDefault();
        event.stopPropagation();
        const open = !switcher.classList.contains('tt-entity-menu-open');
        switcher.classList.toggle('tt-entity-menu-open', open);
        change.setAttribute('aria-expanded', open ? 'true' : 'false');
      };
      switcher.addEventListener('click', event => event.stopPropagation());
      qa('.entityBtn').forEach(button => button.addEventListener('click', () => {
        closeEntityMenu();
        change.setAttribute('aria-expanded', 'false');
      }));
    }
    return true;
  }

  function editorTitle(card) {
    return card.querySelector('h3')?.textContent?.trim() || 'Accounts Entry';
  }

  function openEditor(editor, card) {
    if (!editor) return;
    const workspace = editor.closest('.workspace');
    if (!workspace) return;

    let bar = workspace.querySelector(':scope > .tt-editor-bar');
    if (!bar) {
      bar = document.createElement('div');
      bar.className = 'tt-editor-bar';
      bar.innerHTML = '<button class="backBtn" type="button" data-editor-back>← Back</button><h2></h2>';
      workspace.insertBefore(bar, editor);
    }

    const workspaceTitle = workspace.querySelector(':scope > .panelHead h2')?.textContent?.trim() || 'Accounts';
    bar.querySelector('[data-editor-back]').textContent = '← ' + workspaceTitle;
    bar.querySelector('h2').textContent = editorTitle(card);
    editor.classList.add('tt-editor-stage');
    workspace.classList.add('tt-editor-open');
    window.scrollTo(0, 0);
  }

  function closeEditor(workspace) {
    if (!workspace) return;
    workspace.classList.remove('tt-editor-open');
    workspace.querySelectorAll(':scope > .tt-editor-stage').forEach(editor => editor.classList.remove('tt-editor-stage'));
    window.scrollTo(0, 0);
  }

  document.addEventListener('click', event => {
    const editorBack = event.target.closest?.('[data-editor-back]');
    if (editorBack) {
      event.preventDefault();
      closeEditor(editorBack.closest('.workspace'));
      return;
    }

    const expense = event.target.closest?.('[data-expense]');
    if (expense) window.setTimeout(() => openEditor(q('#expenseEditor'), expense), 0);

    const purchase = event.target.closest?.('[data-purchase]');
    if (purchase) window.setTimeout(() => openEditor(q('#purchaseEditor'), purchase), 0);

    if (event.target.closest?.('.appCard[data-key], [data-back]')) {
      qa('.workspace.tt-editor-open').forEach(closeEditor);
      closeEntityMenu();
    } else if (!event.target.closest?.('#ttChangeEntity, .entitySwitcher')) {
      closeEntityMenu();
      q('#ttChangeEntity')?.setAttribute('aria-expanded', 'false');
    }
  }, true);

  if (!setupDirectLanding()) {
    const setupObserver = new MutationObserver(() => {
      if (setupDirectLanding()) setupObserver.disconnect();
    });
    setupObserver.observe(document.body, {childList:true, subtree:true});
    window.setTimeout(() => setupObserver.disconnect(), 10000);
  }

  window.TT_ACCOUNTS_NAVIGATION = {
    installed: true,
    directLanding: true,
    topLevelEditors: true,
    compactHome: true
  };
})();