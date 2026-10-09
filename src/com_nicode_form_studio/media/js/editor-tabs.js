/** One visible editor panel; switching never detaches or resets draft controls. */
export function mountEditorTabs(root, onActivate = () => {}) {
  const tabs = [...root.querySelectorAll('[data-nfs-tab]')];
  const panels = [...root.querySelectorAll('[data-nfs-tab-panel]')];
  const select = (name, {focus = false, scroll = false} = {}) => {
    const active = tabs.find(tab => tab.dataset.nfsTab === name);
    if (!active) return false;
    for (const tab of tabs) {
      const selected = tab === active;
      tab.setAttribute('aria-selected', String(selected)); tab.tabIndex = selected ? 0 : -1;
    }
    for (const panel of panels) {
      const wasHidden = panel.hidden;
      panel.hidden = panel.dataset.nfsTabPanel !== name;
      if (wasHidden && !panel.hidden) panel.dispatchEvent(new Event('nfs:panelshown'));
    }
    if (scroll) active.closest('[role="tablist"]')?.scrollIntoView({block: 'start', behavior: 'instant'});
    if (focus) requestAnimationFrame(() => active.focus({preventScroll: true}));
    return true;
  };
  for (const tab of tabs) {
    tab.addEventListener('click', () => { select(tab.dataset.nfsTab); onActivate(tab.dataset.nfsTab); });
    tab.addEventListener('keydown', event => {
      const keys = ['ArrowLeft', 'ArrowRight', 'Home', 'End'];
      if (!keys.includes(event.key)) return;
      event.preventDefault();
      const index = tabs.indexOf(tab), rtl = root.ownerDocument?.documentElement.dir === 'rtl';
      const step = (event.key === 'ArrowRight' ? 1 : -1) * (rtl ? -1 : 1);
      const target = event.key === 'Home' ? tabs[0] : event.key === 'End' ? tabs.at(-1) : tabs[(index + step + tabs.length) % tabs.length];
      // Manual activation avoids issuing preview/history requests while arrowing across tabs.
      for (const item of tabs) item.tabIndex = item === target ? 0 : -1;
      target.focus();
    });
  }
  root.addEventListener('nfs:reveal-panel', event => {
    const panel = event.target.closest('[data-nfs-tab-panel]');
    if (panel) select(panel.dataset.nfsTabPanel, {scroll: true});
  });
  return {select};
}

/** Reveal the owning tab and any nested details before focusing a control. */
export function revealEditorTarget(target) {
  const panel = target?.closest?.('[data-nfs-tab-panel]');
  if (!panel) return false;
  panel.dispatchEvent(new Event('nfs:reveal-panel', {bubbles: true}));
  for (let parent = target.parentElement; parent && parent !== panel; parent = parent.parentElement) {
    if (parent.tagName === 'DETAILS') parent.open = true;
  }
  return true;
}
