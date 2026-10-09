import {actionControls} from './admin-toolbar.js';
const t = key => Joomla.Text._(`COM_NICODE_FORM_STUDIO_${key.toUpperCase()}`);
const element = (tag, text) => { const node = document.createElement(tag); if (text !== undefined) node.textContent = text; return node; };

/** File bytes stay in memory; only explicit preview/import sends them to this Joomla site. */
export function mountDefinitionTransfer(root, context = () => null) {
  async function api(task, payload = null, query = {}, controller = 'definition') {
    const url = new URL('index.php', location.href); url.search = new URLSearchParams({option: 'com_nicode_form_studio', task: `${controller}.${task}`, format: 'json', ...query});
    const options = {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}};
    if (payload !== null) { options.method = 'POST'; options.body = new URLSearchParams({[root.dataset.csrf]: '1', payload: JSON.stringify(payload)}); }
    const response = await fetch(url, options); let result;
    try { result = await response.json(); } catch { throw new Error(t('session_error')); }
    if (!response.ok || !result.ok) throw new Error(t(typeof result.error === 'string' ? result.error : 'session_error'));
    return result.data;
  }
  for (const trigger of actionControls(root, '[data-nfs-transfer]')) trigger.addEventListener('click', () => {
    const current = context(); const exporting = trigger.dataset.nfsTransfer === 'export';
    const template = trigger.dataset.nfsTemplateId ? {id: Number(trigger.dataset.nfsTemplateId), revision: Number(trigger.dataset.nfsTemplateRevision)} : null;
    const outerStatus = root.querySelector('[data-nfs-status]');
    if (current?.dirty) { outerStatus.textContent = t('transfer_save_first'); return; }
    const dialog = element('dialog'); dialog.className = 'nfs-confirm nfs-transfer';
    const title = element('h2', t(template ? 'template_create_form' : exporting ? 'definition_export' : 'definition_import')); title.id = `nfs-transfer-${crypto.randomUUID()}`; dialog.setAttribute('aria-labelledby', title.id);
    const form = element('form'), status = element('p'); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
    const output = element('section'); let active = false, documentText = '', reviewed = null;
    function input(key, type, value = '') {
      const label = element('label', t(key)), node = element('input'); node.type = type; node.value = value; label.append(node); form.append(label); return node;
    }
    function select(key, choices) {
      const label = element('label', t(key)), node = element('select');
      for (const [value, caption] of choices) { const option = element('option', t(caption)); option.value = value; node.append(option); }
      label.append(node); form.append(label); return node;
    }
    const mode = exporting ? select('transfer_mode', [['portable', 'transfer_portable'], ['reference-aware', 'transfer_references']]) : null;
    const file = exporting || template ? null : input('transfer_file', 'file');
    if (file) { file.accept = '.json,application/json'; file.required = true; }
    const policy = exporting ? null : select('transfer_policy', [['duplicate', 'transfer_duplicate'], ...(!template ? [['conflict', 'transfer_conflict']] : []), ...(current && !template ? [['update', 'transfer_update']] : [])]);
    if (template) policy.closest('label').hidden = true;
    const name = exporting ? null : input('name', 'text', template ? trigger.dataset.nfsTemplateName : current?.name ?? '');
    const alias = exporting ? null : input('alias', 'text', current ? `${current.alias}-import` : '');
    for (const field of [name, alias]) { if (field) { field.required = true; field.maxLength = 255; } }
    if (alias) alias.pattern = '[a-z0-9]+(?:-[a-z0-9]+)*';
    const help = element('p', t(template ? 'template_create_help' : exporting ? 'transfer_export_help' : 'transfer_import_help')); form.append(help);
    const acknowledgeLabel = element('label'), acknowledge = element('input'); acknowledge.type = 'checkbox'; acknowledgeLabel.append(acknowledge, document.createTextNode(t('transfer_acknowledge'))); acknowledgeLabel.hidden = true;
    const commit = element('button', t('transfer_commit')); commit.type = 'button'; commit.className = 'btn btn-primary'; commit.hidden = true;
    const submit = element('button', t(exporting ? 'definition_export' : 'transfer_preview')); submit.type = 'submit'; submit.className = 'btn btn-primary';
    const cancel = element('button', t('cancel_action')); cancel.type = 'button'; cancel.className = 'btn btn-secondary';
    function invalidate() { reviewed = null; output.replaceChildren(); commit.hidden = true; acknowledgeLabel.hidden = true; acknowledge.checked = false; }
    for (const field of [file, policy, name, alias]) field?.addEventListener('input', invalidate);
    policy?.addEventListener('change', () => { alias.value = policy.value === 'update' ? current.alias : `${current?.alias ?? 'form'}-import`; alias.readOnly = policy.value === 'update'; invalidate(); });
    const close = () => { if (active) return; dialog.close(); dialog.remove(); trigger.focus(); };
    cancel.addEventListener('click', close); dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
    async function run(operation) {
      if (active) return; active = true;
      const controls = [...form.querySelectorAll('input,select,button')].map(node => [node, node.disabled]); controls.forEach(([node]) => { node.disabled = true; });
      status.textContent = t('transfer_working');
      try { await operation(); } catch (error) { status.textContent = error.message || t('unexpected_error'); }
      finally { active = false; controls.forEach(([node, disabled]) => { node.disabled = disabled; }); }
    }
    const choices = () => ({policy: policy.value, name: name.value, alias: alias.value, revision: policy.value === 'update' ? current.revision : 0});
    form.addEventListener('submit', event => {
      event.preventDefault(); if (!form.reportValidity()) return;
      run(async () => {
        if (exporting) {
          const packageData = await api('export', null, {id: current.id, mode: mode.value});
          const url = URL.createObjectURL(new Blob([JSON.stringify(packageData, null, 2)], {type: 'application/json'}));
          const link = element('a'); link.href = url; link.download = `${current.alias}-${mode.value}.json`; document.body.append(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
          status.textContent = t('transfer_downloaded'); return;
        }
        invalidate();
        if (template) reviewed = await api('preview', {...template, name: name.value, alias: alias.value}, {}, 'template');
        else {
          const selected = file.files?.[0]; if (!selected || selected.size > 2097152) throw new Error(t('transfer_file_invalid'));
          documentText = await selected.text(); reviewed = await api('preview', {document: documentText, choices: choices()});
        }
        const heading = element('h3', t('transfer_preview')); output.append(heading);
        const list = element('ul');
        for (const [kind, count] of Object.entries(reviewed.counts)) list.append(element('li', `${t(`transfer_count_${kind}`)}: ${count}`));
        for (const note of reviewed.package.review) list.append(element('li', `${t(`transfer_review_${note.reason}`)} (${note.path})`));
        for (const conflict of reviewed.conflicts) list.append(element('li', `${t(conflict.code.replaceAll('.', '_'))} (${conflict.path})`));
        for (const diagnostic of reviewed.diagnostics) list.append(element('li', `${diagnostic.code}: ${diagnostic.message} (${diagnostic.path})`));
        output.append(list);
        if (reviewed.comparison) {
          const details = element('details'); details.append(element('summary', t('transfer_comparison')), element('pre', JSON.stringify(reviewed.comparison, null, 2))); output.append(details);
        }
        acknowledgeLabel.hidden = !reviewed.requires_review; commit.hidden = !reviewed.can_import_draft;
        status.textContent = t(reviewed.can_import_draft ? 'transfer_ready' : 'transfer_blocked');
      });
    });
    commit.addEventListener('click', () => run(async () => {
      if (!reviewed || (reviewed.requires_review && !acknowledge.checked)) throw new Error(t('transfer_ack_required'));
      const result = template ? await api('apply', {...template, name: reviewed.choices.name, alias: reviewed.choices.alias, review_token: reviewed.review_token, acknowledge_review: acknowledge.checked}, {}, 'template') : await api('import', {document: documentText, choices: reviewed.choices, review_token: reviewed.review_token, acknowledge_review: acknowledge.checked});
      location.assign(`index.php?option=com_nicode_form_studio&view=editor&id=${result.id}`);
    }));
    form.append(status, output, acknowledgeLabel, submit, commit, cancel); dialog.append(title, form); document.body.append(dialog); dialog.showModal(); (file ?? mode ?? name).focus();
  });
}
