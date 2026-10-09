import {revealEditorTarget} from './editor-tabs.js';
/** Resolve only known draft paths; diagnostic text never becomes a CSS selector. */
export function diagnosticTarget(path, draft) {
  if (typeof path !== 'string') return null;
  const translated = /^\/translations\/([a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8}){0,3})(?:\/(.*))?$/.exec(path);
  if (translated && Object.hasOwn(draft.translations ?? {}, translated[1])) {
    const nested = /^(fields|elements|actions)\/(\d+)\/(?:config\/)?(label|help|description|placeholder|title|text|subject|body_text|body_html)$/.exec(translated[2] ?? '');
    const uuid = nested && draft[nested[1]]?.[Number(nested[2])]?.uuid;
    return {kind:'translation',locale:translated[1],path:uuid ? [nested[1],uuid,nested[3]] : null};
  }
  const fieldValidator = /^\/fields\/(\d+)\/validators\/(\d+)(?:\/|$)/.exec(path);
  if (fieldValidator && draft.fields?.[Number(fieldValidator[1])]?.validators?.[Number(fieldValidator[2])]) {
    return {kind:'panel', selector:'[data-nfs-validator-panel]', path:`/fields/${Number(fieldValidator[1])}/validators/${Number(fieldValidator[2])}`};
  }
  const element = /^\/(fields|elements)\/(\d+)(?:\/|$)/.exec(path);
  if (element) {
    const target = draft[element[1]]?.[Number(element[2])];
    return target?.uuid ? {kind:'element', uuid:target.uuid} : null;
  }
  const entry = /^\/(rules|actions|validators)\/(\d+)(?:\/|$)/.exec(path);
  if (entry && draft[entry[1]]?.[Number(entry[2])]) {
    const selector = {rules:'[data-nfs-logic-panel]',actions:'[data-nfs-actions-panel]',validators:'[data-nfs-validator-panel]'}[entry[1]];
    return {kind:'panel',selector,path:`/${entry[1]}/${Number(entry[2])}`};
  }
  return null;
}

export function revealDiagnosticPanel(root, target, schedule = callback => requestAnimationFrame(callback)) {
  const panel = root.querySelector(target.selector);
  if (!panel) return;
  const focus = () => {
    const card = [...panel.querySelectorAll('[data-nfs-diagnostic-path]')].find(node => node.dataset.nfsDiagnosticPath === target.path);
    const control = card?.querySelector('input:not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled])') || panel.querySelector('summary') || panel;
    control?.focus();
  };
  // Editors rebuild their cards on toggle. Run after their registered listeners.
  const afterRender = () => schedule(focus);
  if (revealEditorTarget(panel)) { afterRender(); return; }
  if (!panel.open) { panel.addEventListener('toggle', afterRender, {once:true}); panel.open = true; }
  else afterRender();
}
