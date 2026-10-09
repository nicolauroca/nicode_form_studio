import {confirmAction} from './confirm-action.js';
const t = key => Joomla.Text._(`COM_NICODE_FORM_STUDIO_${key.toUpperCase()}`);
for (const root of document.querySelectorAll('[data-nfs-submission]')) {
  for (const [selector, task] of [['[data-nfs-state]', 'state'], ['[data-nfs-note]', 'note'], ['[data-nfs-retry]', 'retry']]) {
    for (const target of root.querySelectorAll(selector)) target.addEventListener('submit', async event => {
      event.preventDefault(); const form = event.currentTarget;
      if (task === 'retry' && !await confirmAction(t('confirm_action_retry'))) return;
      if (form.dataset.busy === 'true') return;
      form.dataset.busy = 'true'; const button = form.querySelector('button'); button.disabled = true;
      try {
        const data = Object.fromEntries(new FormData(form));
        const url = new URL('index.php', location.href); url.search = new URLSearchParams({option: 'com_nicode_form_studio', task: `submission.${task}`, format: 'json'});
        const response = await fetch(url, {method: 'POST', credentials: 'same-origin', cache: 'no-store', body: new URLSearchParams({[root.dataset.csrf]: '1', payload: JSON.stringify({...data, expected: form.dataset.expected, id: Number(root.dataset.id), form_id: Number(root.dataset.formId)})})});
        let result;
        try { result = await response.json(); } catch { throw new Error(t('session_error')); }
        if (!response.ok || !result.ok) throw new Error(t(result.error || 'unexpected_error'));
        if (task === 'retry') location.assign('index.php?option=com_nicode_form_studio&view=jobs');
        else location.reload();
      } catch (error) { root.querySelector('[data-nfs-submission-status]').textContent = error.message; button.disabled = false; form.dataset.busy = 'false'; }
    });
  }
  const reveal = root.querySelector('[data-nfs-reveal]');
  reveal?.addEventListener('click', async () => {
    reveal.disabled = true;
    const status = root.querySelector('[data-nfs-submission-status]');
    try {
      const url = new URL('index.php', location.href);
      url.search = new URLSearchParams({option: 'com_nicode_form_studio', task: 'submission.reveal', format: 'json'});
      const response = await fetch(url, {method: 'POST', credentials: 'same-origin', cache: 'no-store', body: new URLSearchParams({[root.dataset.csrf]: '1', payload: JSON.stringify({id: Number(root.dataset.id), form_id: Number(root.dataset.formId)})})});
      let result;
      try { result = await response.json(); } catch { throw new Error(t('session_error')); }
      if (!response.ok || !result.ok) throw new Error(t(result.error || 'unexpected_error'));
      for (const text of root.querySelectorAll('[data-nfs-answer]')) {
        const uuid = text.dataset.nfsAnswer; const value = result.data.values[uuid];
        text.textContent = value === undefined ? '' : typeof value === 'string' ? value : JSON.stringify(value, null, 2);
      }
      for (const label of root.querySelectorAll('[data-nfs-option-label]')) {
        label.textContent = result.data.option_labels[label.dataset.nfsOptionLabel] ?? '';
      }
      const consents = root.querySelector('[data-nfs-consents]'); consents.replaceChildren();
      const metadata = root.querySelector('[data-nfs-request-metadata]');
      if (metadata) {
        const list = metadata.querySelector('dl'); list.replaceChildren();
        for (const key of ['ip', 'user_agent']) {
          if (result.data.request_metadata?.[key] === undefined) continue;
          const label = document.createElement('dt'), value = document.createElement('dd');
          label.textContent = t(`request_${key}`); value.textContent = result.data.request_metadata[key];
          list.append(label, value);
        }
        metadata.hidden = list.children.length === 0;
      }
      for (const [uuid, consent] of Object.entries(result.data.consents)) {
        const article = document.createElement('article'), title = document.createElement('h3'), list = document.createElement('dl');
        title.textContent = result.data.labels[uuid] ?? uuid;
        for (const key of ['accepted', 'text', 'received_at', 'form_version_id']) {
          const label = document.createElement('dt'), value = document.createElement('dd');
          label.textContent = t(`consent_${key}`); value.textContent = key === 'accepted' ? t(consent[key] ? 'consent_yes' : 'consent_no') : (consent[key] ?? '—');
          list.append(label, value);
        }
        article.append(title, list); consents.append(article);
      }
      const files = root.querySelector('[data-nfs-files]'); files.replaceChildren();
      for (const file of result.data.files) {
        const form = document.createElement('form'); form.method = 'post'; form.action = 'index.php';
        for (const [name, value] of Object.entries({option: 'com_nicode_form_studio', task: 'submission.download', format: 'raw', form_id: root.dataset.formId, file: file.uuid, [root.dataset.csrf]: '1'})) {
          const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; form.append(input);
        }
        const button = document.createElement('button'); button.type = 'submit'; button.className = 'btn btn-secondary';
        button.textContent = `${t('download')}: ${file.original_name} (${file.size_bytes} B)`; form.append(button); files.append(form);
      }
      root.querySelector('[data-nfs-masked]')?.remove(); reveal.remove(); status.textContent = t('reveal_audited');
    } catch (error) { status.textContent = error.message; reveal.disabled = false; }
  });
}
