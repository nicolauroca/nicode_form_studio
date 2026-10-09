import {actionControls} from './admin-toolbar.js';
import {configurationObject} from './builder-model.js';
import {mountDefinitionTransfer} from './definition-transfer.js';

const t = key => Joomla.Text._(`COM_NICODE_FORM_STUDIO_${key.toUpperCase()}`);
const node = (tag, text, attributes = {}) => { const result = document.createElement(tag); if (text !== undefined) result.textContent = text; for (const [key, value] of Object.entries(attributes)) result.setAttribute(key, value); return result; };
const button = (text, action) => { const result = node('button', text, {type: 'button', class: 'btn btn-secondary'}); result.addEventListener('click', action); return result; };
async function api(csrf, task, payload = null, query = {}) {
  const url = new URL('index.php', location.href); url.search = new URLSearchParams({option: 'com_nicode_form_studio', task: `template.${task}`, format: 'json', ...query});
  const options = {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}};
  if (payload !== null) { options.method = 'POST'; options.body = new URLSearchParams({[csrf]: '1', payload: JSON.stringify(payload)}); }
  const response = await fetch(url, options); let result;
  try { result = await response.json(); } catch { throw new Error(t('session_error')); }
  if (!response.ok || !result.ok) throw new Error(t(typeof result.error === 'string' ? result.error : 'session_error'));
  return result.data;
}
export function dialog(trigger, title) {
  const modal = node('dialog', undefined, {class: 'nfs-confirm'}), heading = node('h2', title, {id: `nfs-template-${crypto.randomUUID()}`}); modal.setAttribute('aria-labelledby', heading.id);
  const form = node('form'), content = node('div'), status = node('p', '', {role: 'status', 'aria-live': 'polite'}); let busy = false, finished = false;
  const close = () => { if (busy) return; modal.close(); modal.remove(); trigger.focus(); };
  const cancel = button(t('cancel_action'), close); form.addEventListener('submit', event => event.preventDefault()); modal.addEventListener('cancel', event => { event.preventDefault(); close(); });
  form.append(content, status, cancel); modal.append(heading, form); document.body.append(modal); modal.showModal(); cancel.focus();
  const run = async operation => {
    if (busy) return; busy = true; const controls = [...form.querySelectorAll('input,textarea,select,button')].map(input => [input, input.disabled]); controls.forEach(([input]) => { input.disabled = true; });
    try { await operation(); } catch (error) { status.textContent = error.message || t('unexpected_error'); }
    finally { busy = false; controls.forEach(([input, disabled]) => { input.disabled = finished && input !== cancel ? true : disabled; }); if (finished) cancel.focus(); }
  };
  const finish = () => { finished = true; cancel.textContent = t('close_action'); };
  return {form, content, status, close, run, finish};
}
function labelled(host, title, input) { const label = node('label', title); input.setAttribute('aria-label', title); label.append(input); host.append(label); return input; }

for (const root of document.querySelectorAll('[data-nfs-templates]')) {
  mountDefinitionTransfer(root);
  root.querySelector('[data-nfs-email-template-create]')?.addEventListener('submit', async event => {
    event.preventDefault(); const form = event.target; if (!form.reportValidity()) return;
    const control = form.querySelector('button'); if (control.disabled) return; control.disabled = true;
    try { const result = await api(root.dataset.csrf, 'create', {name: new FormData(form).get('name')}); location.assign(`index.php?option=com_nicode_form_studio&view=emailtemplate&id=${result.id}`); }
    catch (error) { root.querySelector('[data-nfs-template-status]').textContent = error.message; }
    finally { control.disabled = false; }
  });
}
for (const root of document.querySelectorAll('[data-nfs-email-template]')) {
  let dirty = false, busy = false; const form = root.querySelector('form'), status = root.querySelector('[data-nfs-template-status]');
  form.addEventListener('input', () => { dirty = true; status.textContent = t('unsaved'); });
  window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
  form.addEventListener('submit', async event => {
    event.preventDefault(); if (busy || !form.reportValidity()) return; const data = new FormData(form); busy = true; form.querySelector('fieldset').disabled = true;
    try {
      const result = await api(root.dataset.csrf, 'save', {id: Number(root.dataset.id), revision: Number(root.dataset.revision), name: data.get('name'), language: data.get('language'), content: {subject: data.get('subject'), body_text: data.get('body_text'), body_html: data.get('body_html')}});
      root.dataset.revision = result.revision; root.querySelector('h1').textContent = data.get('name'); dirty = false; status.textContent = t('template_saved');
    } catch (error) { status.textContent = error.message; }
    finally { busy = false; form.querySelector('fieldset').disabled = false; }
  });
}

export function mountTemplateCapture(root, context) {
  const trigger = actionControls(root, '[data-nfs-template-capture]')[0]; if (!trigger) return;
  trigger.addEventListener('click', () => {
    const current = context(); if (current.dirty) { root.querySelector('[data-nfs-status]').textContent = t('transfer_save_first'); return; }
    const ui = dialog(trigger, t('template_capture')), name = labelled(ui.content, t('name'), node('input', undefined, {required: 'required', maxlength: '255'})); name.value = current.name;
    const selected = labelled(ui.content, t('template_destination'), node('select')); selected.append(node('option', t('template_new'), {value: '0'}));
    let before = null, captured = false; const records = new Map();
    const more = button(t('template_load_existing'), () => ui.run(async () => {
      const page = await api(root.dataset.csrf, 'listing', null, {kind: 'form', ...(before ? {before} : {})});
      for (const record of page.rows) { records.set(String(record.id), record); selected.append(node('option', record.name, {value: record.id})); }
      before = page.next_before; more.hidden = !before;
    })); ui.content.append(more, node('p', t('form_template_help')));
    selected.addEventListener('change', () => { name.value = records.get(selected.value)?.name ?? current.name; });
    ui.content.append(button(t('template_capture'), () => {
      if (captured || !ui.form.reportValidity()) return;
      ui.run(async () => {
        const record = records.get(selected.value);
        const result = await api(root.dataset.csrf, 'capture', {name: name.value, form_id: current.id, form_revision: current.revision, id: record?.id ?? 0, revision: record?.revision ?? 0});
        captured = true;
        ui.finish();
        ui.status.textContent = t('template_saved');
        const review = node('ul'); for (const note of result.review) review.append(node('li', `${t(`transfer_review_${note.reason}`)} (${note.path})`)); ui.content.append(review);
        const link = node('a', t('form_templates'), {href: 'index.php?option=com_nicode_form_studio&view=templates&kind=form'}); ui.content.append(link);
      });
    })); name.focus();
  });
}

export function mountEmailTemplatePicker(host, {root, draft, action, formId, isDirty, changed, redraw}) {
  const trigger = button(t('template_apply_email'), () => {
    if (isDirty()) { root.querySelector('[data-nfs-status]').textContent = t('transfer_save_first'); return; }
    const ui = dialog(trigger, t('template_apply_email'));
    const selected = labelled(ui.content, t('email_templates'), node('select', undefined, {required: 'required'}));
    const target = labelled(ui.content, t('template_apply_to'), node('select')); target.append(node('option', t('template_base_content'), {value: 'base'}), node('option', t('template_language_content'), {value: 'language'}));
    const bindings = node('div'), preview = node('pre'); ui.content.append(bindings, preview);
    let record = null, before = null; const mapping = new Map();
    const reset = () => { record = null; mapping.clear(); bindings.replaceChildren(); preview.textContent = ''; };
    selected.addEventListener('change', reset);
    const more = button(t('template_load'), () => ui.run(async () => {
      const page = await api(root.dataset.csrf, 'listing', null, {kind: 'email', ...(before ? {before} : {})});
      for (const row of page.rows) selected.append(node('option', `${row.name} · ${row.language}`, {value: row.id}));
      before = page.next_before; more.hidden = !before;
    }));
    ui.content.append(more, button(t('template_open'), () => ui.run(async () => {
      reset(); if (!selected.value) throw new Error(t('invalid_request'));
      record = await api(root.dataset.csrf, 'record', null, {kind: 'email', id: selected.value});
      preview.textContent = `${record.language}\n${record.subject}\n\n${record.body_text}`;
      for (const parameter of record.parameters) {
        const input = labelled(bindings, parameter, node('select', undefined, {required: 'required'})); input.append(node('option', t('none'), {value: ''}));
        for (const field of draft.fields.filter(field => field.type !== 'password' && (field.include_email ?? !field.sensitive))) input.append(node('option', field.config?.label || field.name, {value: field.uuid}));
        mapping.set(parameter, input);
      }
    })), button(t('template_apply_email'), () => {
      if (!ui.form.reportValidity()) return;
      ui.run(async () => {
        if (!record || record.revision < 1) throw new Error(t('template_save_required'));
        const result = await api(root.dataset.csrf, 'bind', {id: record.id, revision: record.revision, form_id: formId, bindings: Object.fromEntries([...mapping].map(([key, input]) => [key, input.value]))});
        if (target.value === 'base') {
          for (const key of ['subject', 'body_text', 'body_html', 'template']) delete action.config[key]; Object.assign(action.config, result.config); action.config.email_format = result.config.body_html ? 'html' : 'text';
        } else {
          draft.translations = configurationObject(draft.translations); const locale = Object.keys(draft.translations).find(value => value.toLowerCase() === result.language.toLowerCase()) ?? result.language;
          const translation = draft.translations[locale] = configurationObject(draft.translations[locale]); translation.actions = configurationObject(translation.actions);
          translation.actions[action.uuid] = {...configurationObject(translation.actions[action.uuid]), subject: result.config.subject, body_text: result.config.body_text, body_html: result.config.body_html ?? ''};
          action.config.template_translations = configurationObject(action.config.template_translations); action.config.template_translations[locale] = result.config.template;
        }
        changed(); redraw(); ui.status.textContent = t('template_applied'); ui.finish();
      });
    }));
  }); host.append(trigger);
}
