/** Include only this component's native toolbar controls, never other Joomla actions. */
export function actionControls(root, selector) {
  const local = [...root.querySelectorAll(selector)];
  const external = [...(root.ownerDocument?.querySelectorAll('[data-nfs-toolbar]') ?? [])].filter(control => control.matches(selector));
  return [...new Set([...local, ...external])];
}

for (const trigger of globalThis.document?.querySelectorAll('[data-nfs-reveal]') ?? []) {
  trigger.addEventListener('click', () => {
    const target = document.querySelector(trigger.dataset.nfsReveal);
    if (!target) return;
    for (let parent = target.parentElement; parent; parent = parent.parentElement) if (parent.tagName === 'DETAILS') parent.open = true;
    target.scrollIntoView({block: 'center'});
    target.querySelector('input,select,textarea')?.focus();
  });
}
for (const trigger of globalThis.document?.querySelectorAll('[data-nfs-submit]') ?? []) {
  const form = document.querySelector(trigger.dataset.nfsSubmit);
  const submit = form?.querySelector('button[type="submit"]');
  if (!submit) { trigger.disabled = true; continue; }
  const sync = () => { trigger.disabled = submit.matches(':disabled'); };
  sync();
  new MutationObserver(sync).observe(form, {subtree: true, attributes: true, attributeFilter: ['disabled']});
  trigger.addEventListener('click', () => { if (!submit.matches(':disabled')) form.requestSubmit(submit); });
}
