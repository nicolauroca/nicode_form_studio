/** Native dialog confines required copy fields to this operation. */
export function duplicateFormDetails(name, alias) {
  const t = key => Joomla.Text._(`COM_NICODE_FORM_STUDIO_${key.toUpperCase()}`);
  return new Promise(resolve => {
    const previous = document.activeElement, dialog = document.createElement('dialog'), form = document.createElement('form'); dialog.className = 'nfs-confirm';
    const title = document.createElement('h2'); title.id = `nfs-copy-${crypto.randomUUID()}`; title.textContent = t('duplicate'); dialog.setAttribute('aria-labelledby', title.id);
    const help = document.createElement('p'); help.textContent = t('duplicate_help');
    const inputs = {};
    for (const [key, value] of [['name', `${name} ${t('copy_suffix')}`], ['alias', `${alias}-copy`]]) {
      const label = document.createElement('label'); label.textContent = t(key);
      const input = document.createElement('input'); input.name = key; input.value = value.slice(0, 255); input.required = true; input.maxLength = 255;
      if (key === 'alias') input.pattern = '[a-z0-9]+(?:-[a-z0-9]+)*';
      label.append(input); form.append(label); inputs[key] = input;
    }
    const cancel = document.createElement('button'); cancel.type = 'button'; cancel.textContent = t('cancel_action'); cancel.className = 'btn btn-secondary';
    const submit = document.createElement('button'); submit.type = 'submit'; submit.textContent = t('duplicate'); submit.className = 'btn btn-primary';
    let settled = false;
    const finish = value => { if (settled) return; settled = true; dialog.close(); dialog.remove(); previous?.focus(); resolve(value); };
    form.addEventListener('submit', event => { event.preventDefault(); if (form.reportValidity()) finish({name: inputs.name.value, alias: inputs.alias.value}); });
    cancel.addEventListener('click', () => finish(null)); dialog.addEventListener('cancel', event => { event.preventDefault(); finish(null); });
    form.append(cancel, submit); dialog.append(title, help, form); document.body.append(dialog); dialog.showModal(); inputs.name.focus();
  });
}
