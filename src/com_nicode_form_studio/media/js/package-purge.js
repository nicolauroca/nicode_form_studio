import {confirmAction} from './confirm-action.js';
const t = key => Joomla.Text._(`COM_NICODE_FORM_STUDIO_${key.toUpperCase()}`);
for (const root of document.querySelectorAll('[data-nfs-purge]')) {
  root.querySelector('[data-nfs-purge-confirm]')?.addEventListener('submit', async event => {
    event.preventDefault(); const form = event.currentTarget; const button = form.querySelector('button');
    if (button.disabled || !form.reportValidity()) return;
    if (!await confirmAction(t('purge_help'))) return;
    const payload = new FormData(form); payload.set(root.dataset.csrf, '1'); button.disabled = true;
    try {
      const response = await fetch('index.php?option=com_nicode_form_studio&task=purge.prepare&format=json', {method:'POST', body:new URLSearchParams(payload), credentials:'same-origin', cache:'no-store'});
      const result = await response.json(); if (!response.ok || !result.ok) throw new Error(t(result.error || 'unexpected_error'));
      location.assign('index.php?option=com_nicode_form_studio&view=jobs');
    } catch (error) { root.querySelector('[data-nfs-purge-status]').textContent = error.message || t('unexpected_error'); button.disabled = false; button.focus(); }
  });
}
