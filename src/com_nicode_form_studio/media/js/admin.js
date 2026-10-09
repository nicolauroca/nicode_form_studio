import {mountEditorTabs, revealEditorTarget} from './editor-tabs.js';
import {actionControls} from './admin-toolbar.js';
import {containers, decorations, descendants, moveElement, reparentElement, removeElement, addElement, configurationObject, supportsStepParent, insertionParent, revealAncestors} from './builder-model.js';
import {mountAutomationEditor} from './automation-editor.js';
import {mountBuilderDrag} from './builder-drag.js';
import {createInitializer} from './initialize.js';
import {createFormInstance} from './form-instance.js';
import './submission-admin.js';
import './submission-search.js';
import './jobs.js';
import './package-purge.js';
import {mountOptionSetPicker} from './option-sets.js';
import {mountEntitySource} from './entity-sources.js';
import {confirmAction} from './confirm-action.js';
import {duplicateFormDetails} from './duplicate-form.js';
import {mountDefinitionTransfer} from './definition-transfer.js';
import {mountTranslationEditor, messageCodes} from './translation-editor.js';
import {mountTemplateCapture} from './templates.js';
import {mountValidatorEditor} from './validator-editor.js';
import {diagnosticTarget, revealDiagnosticPanel} from './diagnostic-target.js';
import {mountPrefillEditor} from './prefill-editor.js';
import {mountSourceResources} from './source-resources.js';
import {mountFormSelection} from './form-selection.js';
import {bindIntegerControl} from './integer-control.js';
import {mountRepeatLimits} from './repeat-limits-editor.js';
import {authoringContainers} from './builder-model.js';
import {builderTypeLabel} from './builder-labels.js';

const t = key => Joomla.Text._(`COM_NICODE_FORM_STUDIO_${key.toUpperCase()}`);
const node = (tag, text, attributes = {}) => {
  const element = document.createElement(tag);
  if (text !== undefined) element.textContent = text;
  for (const [name, value] of Object.entries(attributes)) element.setAttribute(name, value);
  return element;
};
const button = (text, action, attributes = {}) => {
  const element = node('button', text, {type: 'button', class: 'btn btn-secondary', ...attributes});
  element.addEventListener('click', action); return element;
};

for (const root of document.querySelectorAll('[data-nfs-admin]')) {
  const status = root.querySelector('[data-nfs-status]');
  const announce = (message, error = false) => { status.textContent = message; status.dataset.error = String(error); };
  let busy = false;
  const tabs = root.hasAttribute('data-nfs-editor') ? mountEditorTabs(root, name => {
    const command = {preview:'preview', versions:'history'}[name];
    if (command) actionControls(root, '[data-nfs-command]').find(control => control.dataset.nfsCommand === command)?.click();
  }) : null;
  async function api(operation, payload = null, query = {}, signal = undefined) {
    const url = new URL('index.php', location.href);
    url.search = new URLSearchParams({option: 'com_nicode_form_studio', task: `form.${operation}`, format: 'json', ...query});
    const options = {credentials: 'same-origin', cache: 'no-store', signal, headers: {Accept: 'application/json'}};
    if (payload !== null) { options.method = 'POST'; options.body = new URLSearchParams({payload: JSON.stringify(payload), [root.dataset.csrf]: '1'}); }
    const response = await fetch(url, options);
    let result;
    try { result = await response.json(); } catch { throw new Error(t('session_error')); }
    if (!response.ok || !result.ok) {
      const error = new Error(t(typeof result.error === 'string' ? result.error : 'session_error')); error.diagnostics = Array.isArray(result.diagnostics) ? result.diagnostics : []; throw error;
    }
    return result.data;
  }
  async function run(operation, validate = true) {
    if (busy) return;
    for (const control of root.querySelectorAll('input,select,textarea')) {
      if (validate && !control.checkValidity()) { revealEditorTarget(control);
        const invalid = [...root.querySelectorAll('input,select,textarea')].find(input => !input.checkValidity());
        invalid?.reportValidity(); announce(t('invalid_request'), true); return; }
    }
    busy = true;
    const previousFocus = document.activeElement;
    const controls = actionControls(root, 'button,input,select,textarea').map(control => [control, control.disabled]);
    for (const [control] of controls) control.disabled = true;
    try { await operation(); }
    catch (error) { announce(error.message || t('unexpected_error'), true); if (error.diagnostics) diagnostics(error.diagnostics); }
    finally { for (const [control, disabled] of controls) control.disabled = disabled; busy = false; if (previousFocus?.isConnected && !previousFocus.disabled) previousFocus.focus({preventScroll:true}); }
  }
  root.querySelector('[data-nfs-create]')?.addEventListener('submit', event => {
    event.preventDefault(); const data = new FormData(event.target);
    run(async () => {
      const result = await api('create', {name: data.get('name'), alias: data.get('alias')});
      location.href = `index.php?option=com_nicode_form_studio&view=editor&id=${result.id}`;
    });
  });
  if (!root.hasAttribute('data-nfs-editor')) { mountDefinitionTransfer(root); mountFormSelection(root, api, run, announce); continue; }
  const data = JSON.parse(root.querySelector('[data-nfs-editor-data]').textContent);
  const draft = data.draft;
  const previewViewport = root.querySelector('[data-nfs-preview-viewport]');
  previewViewport.addEventListener('change', () => {
    if (['1280', '800', '390'].includes(previewViewport.value)) {
      root.querySelector('[data-nfs-preview] iframe').setAttribute('width', previewViewport.value);
    }
  });
  let revision = Number(data.form.draft_revision), dirty = false, settingsDirty = false, permissionsDirty = false, selected = null;
  mountDefinitionTransfer(root, () => ({id: Number(data.form.id), revision, name: draft.name, alias: data.form.alias, dirty: dirty || settingsDirty || permissionsDirty || Boolean(root.querySelector('[data-nfs-publish-comment]')?.value)}));
  mountTemplateCapture(root, () => ({id: Number(data.form.id), revision, name: draft.name, dirty: dirty || settingsDirty || permissionsDirty}));
  const changed = () => { dirty = true; diagnostics([]); announce(t('unsaved')); };
  window.addEventListener('beforeunload', event => { if (dirty || settingsDirty || permissionsDirty || root.querySelector('[data-nfs-publish-comment]')?.value) { event.preventDefault(); event.returnValue = ''; } });
  for (const input of root.querySelectorAll('[data-nfs-setting]')) input.addEventListener('input', () => { settingsDirty = true; announce(t('settings_unsaved')); });
  root.querySelector('[data-nfs-name]').addEventListener('input', event => { draft.name = event.target.value; changed(); });
  const typeLabel = (type, field = false) => builderTypeLabel(type, field ? data.providers.fields[type] : {label_key:`COM_NICODE_FORM_STUDIO_LAYOUT_TYPE_${type.replaceAll('-', '_').toUpperCase()}`}, key => Joomla.Text._(key));
  const label = element => { const field = draft.fields.find(field => field.uuid === element.uuid); const config = field?.config; return config?.admin_label || config?.label || element.title || element.text || typeLabel(field?.type ?? element.type, Boolean(field)); };
  const current = () => draft.elements.find(element => element.uuid === selected);
  const collapsed = new Set();
  const choose = uuid => { selected = uuid; revealAncestors(draft, uuid, collapsed); renderTree(); renderInspector(); };
  mountBuilderDrag(root.querySelector('[data-nfs-tree]'), draft, uuid => {
    changed(); choose(uuid);
    root.querySelector(`[data-nfs-tree-item="${uuid}"]`)?.focus();
  });
  function diagnostics(items) {
    const section = root.querySelector('[data-nfs-diagnostics]');
    if (!section) return;
    section.hidden = items.length === 0; const list = section.querySelector('ul'); list.replaceChildren();
    for (const item of items) {
      const severity = ['ERROR', 'WARNING', 'INFO'].includes(item.severity) ? item.severity.toLowerCase() : 'error';
      const li = node('li'); li.append(node('strong', `${t(`diagnostic_${severity}`)}: ${item.code} `), node('span', `${item.message} (${item.path})`));
      const target = diagnosticTarget(item.path, draft);
      if (target) li.append(button(t('locate'), () => {
        if (target.kind === 'translation') translationEditor.reveal(target.locale, target.path);
        else if (target.kind === 'panel') revealDiagnosticPanel(root, target);
        else { tabs.select('fields', {scroll:true}); choose(target.uuid); root.querySelector('[data-nfs-inspector] input, [data-nfs-inspector] select, [data-nfs-inspector] textarea')?.focus(); }
      }));
      list.append(li);
    }
  }
  function renderTree() {
    const tree = root.querySelector('[data-nfs-tree]'); tree.replaceChildren(); const visited = new Set();
    const branch = parent => {
      const list = node('ul', undefined, {class: 'nfs-tree-list'});
      for (const element of draft.elements.filter(item => (item.parent_uuid ?? null) === parent)) {
        if (visited.has(element.uuid)) continue; visited.add(element.uuid);
        const li = node('li'), row = node('div', undefined, {class:'nfs-tree-row'});
        const children = branch(element.uuid);
        if (children.childElementCount) {
          children.id = `nfs-tree-${data.form.id}-${element.uuid}`; children.hidden = collapsed.has(element.uuid);
          row.append(button(children.hidden ? '+' : '−', () => {
            if (collapsed.has(element.uuid)) collapsed.delete(element.uuid); else collapsed.add(element.uuid);
            renderTree(); root.querySelector(`[data-nfs-tree-toggle="${element.uuid}"]`)?.focus();
          }, {'aria-label':`${t(children.hidden ? 'expand_branch' : 'collapse_branch')}: ${label(element)}`, 'aria-expanded':String(!children.hidden), 'aria-controls':children.id, 'data-nfs-tree-toggle':element.uuid}));
        }
        row.append(button(label(element), () => choose(element.uuid), {'aria-pressed': String(element.uuid === selected), draggable:'true', 'data-nfs-tree-item':element.uuid}));
        li.append(row); if (children.childElementCount) li.append(children); list.append(li);
      }
      return list;
    };
    tree.append(node('p', t('drag_help')));
    tree.append(node('div', t('root'), {'data-nfs-tree-root':'',class:'nfs-tree-root-drop'}));
    tree.append(branch(null));
    if (!draft.elements.length) tree.append(node('p', t('empty_builder')));
    // Invalid imported drafts remain inspectable instead of disappearing from the tree.
    for (const element of draft.elements) if (!visited.has(element.uuid)) tree.append(button(`${t('orphan')}: ${label(element)}`, () => choose(element.uuid)));
  }
  function control(host, title, object, key, schema = {}, options = {}) {
    const wrap = node('label', title);
    const input = node(schema.enum ? 'select' : schema.type === 'array' || options.multiline ? 'textarea' : 'input');
    input.setAttribute('aria-label', title);
    if (schema.enum) for (const item of schema.enum) input.append(node('option', schema.enumLabels?.[item] ?? String(item), {value: String(item)}));
    else if (input.tagName === 'INPUT') input.type = schema.type === 'boolean' ? 'checkbox' : schema.type === 'integer' ? 'number' : 'text';
    if (schema.minimum !== undefined) input.min = schema.minimum;
    if (schema.maximum !== undefined) input.max = schema.maximum;
    if (schema.type === 'integer') input.step = '1';
    if (schema.type === 'boolean') input.checked = object[key] ?? options.default ?? schema.default ?? false;
    else input.value = schema.type === 'array' ? (object[key] ?? []).join('\n') : object[key] ?? options.default ?? schema.default ?? schema.enum?.[0] ?? '';
    if (schema.type === 'integer') {
      bindIntegerControl(input, object, key, {required:options.required, message:t('invalid_request'), changed:() => { changed(); options.onChange?.(); }});
      wrap.append(input); host.append(wrap); return input;
    }
    input.addEventListener(schema.enum || schema.type === 'boolean' ? 'change' : 'input', () => {
      if (schema.type === 'boolean') object[key] = input.checked;
      else if (input.value === '' && !options.required) delete object[key];
      else if (schema.type === 'array') object[key] = input.value.split(/\r?\n/).filter(value => value !== '');
      else object[key] = input.value;
      changed(); if (options.onChange) options.onChange();
    });
    wrap.append(input); host.append(wrap); return input;
  }
  function renderInspector() {
    const inspector = root.querySelector('[data-nfs-inspector]'); inspector.replaceChildren(); const element = current();
    if (!element) { inspector.append(node('p', t('select_element'))); return; }
    inspector.append(node('p', `${element.type} · ${element.uuid}`));
    const actions = node('div', undefined, {class: 'nfs-inspector-actions'});
    actions.append(button(t('duplicate_element'), () => run(async () => {
      if (dirty) await save();
      const result = await api('duplicateElement', {id:Number(data.form.id), revision, element:selected});
      for (const key of ['elements','fields','rules','translations']) {
        if (Object.hasOwn(result.draft, key)) draft[key] = result.draft[key];
      }
      revision = result.revision; dirty = false;
      for (const panel of root.querySelectorAll('[data-nfs-logic-panel], [data-nfs-validator-panel]')) panel.dispatchEvent(new Event('nfs:panelshown'));
      root.querySelector('[data-nfs-translations]')?.closest('[data-nfs-tab-panel]')?.dispatchEvent(new Event('nfs:panelshown'));
      choose(result.selected); announce(t('saved'));
    })));
    for (const [key, direction] of [['move_up', -1], ['move_down', 1]]) actions.append(button(t(key), () => { if (moveElement(draft, selected, direction)) { changed(); renderTree(); } }));
    actions.append(button(t('remove'), async () => {
      if (!await confirmAction(t('remove_help'))) return;
      removeElement(draft, selected); selected = null; changed(); renderTree(); renderInspector();
    }, {class: 'btn btn-danger'})); inspector.append(actions);
    const parentLabel = node('label', t('parent')); const parent = node('select', undefined, {'aria-label': t('parent')}); parent.append(node('option', t('root'), {value: ''}));
    const excluded = descendants(draft, selected);
    for (const candidate of draft.elements) if (containers.includes(candidate.type) && !excluded.has(candidate.uuid) && supportsStepParent(draft, selected, candidate.uuid)) parent.append(node('option', label(candidate), {value: candidate.uuid}));
    parent.value = element.parent_uuid ?? '';
    parent.addEventListener('change', () => { reparentElement(draft, selected, parent.value || null); revealAncestors(draft, selected, collapsed); changed(); renderTree(); }); parentLabel.append(parent); inspector.append(parentLabel);
    element.width = configurationObject(element.width);
    for (const breakpoint of ['mobile', 'tablet', 'desktop']) control(inspector, `${t('width')} · ${t(`preview_${breakpoint}`)}`, element.width, breakpoint, {
      type: 'integer', enum: ['', ...Array.from({length:12}, (_, index) => index + 1)], enumLabels: {'':t('width_default')}, minimum: 1, maximum: 12,
    });
    inspector.append(node('p', t('width_help')));
    if (element.type !== 'field') {
      control(inspector, t('property_visible'), element, 'visible', {type:'boolean',default:true});
      if (containers.includes(element.type)) {
        control(inspector, t('title'), element, 'title', {type: 'string'}, {onChange: renderTree});
        if (element.type === 'step') control(inspector, t('property_description'), element, 'description', {type: 'string'}, {multiline: true});
        mountRepeatLimits(inspector, element, {control, node, t});
      }
      else if (!['captcha', 'separator', 'spacer'].includes(element.type)) control(inspector, t('text'), element, 'text', {type: 'string'}, {multiline: true, onChange: renderTree});
      return;
    }
    const field = draft.fields.find(item => item.uuid === selected); if (!field) return;
    const metadata = data.providers.fields[field.type] ?? {}; field.config = configurationObject(field.config);
    const nameWarning = node('p', undefined, {role:'status','data-nfs-name-warning':''});
    const updateNameWarning = () => {
      const previous = data.published_names?.[field.uuid];
      nameWarning.hidden = previous === undefined || previous === field.name;
      nameWarning.textContent = nameWarning.hidden ? '' : t('machine_rename_warning').replace('%s', previous);
    };
    const machineName = control(inspector, t('machine_name'), field, 'name', {type: 'string'}, {required: true, onChange:updateNameWarning});
    machineName.maxLength = 255; machineName.pattern = '[a-z][a-z0-9_]*'; machineName.required = true;
    updateNameWarning(); inspector.append(nameWarning);
    const properties = {...metadata.configuration_schema?.properties};
    Object.assign(properties, {label: {type: 'string'}, required: {type: 'boolean'}, readonly: {type: 'boolean'}, disabled: {type: 'boolean'}});
    if (metadata.prefill) properties.default = {type: metadata.multiple ? 'array' : metadata.datatype === 'boolean' ? 'boolean' : 'string'};
    for (const [key, schema] of Object.entries(properties)) control(inspector, t(`property_${key}`), field.config, key, schema, {onChange: ['label', 'admin_label'].includes(key) ? renderTree : null, multiline:['description','help'].includes(key)});
    mountPrefillEditor(inspector, field, draft.fields, metadata, {node, control, changed, redraw: renderInspector, t});
    for (const key of ['persist', 'sensitive', 'include_email', 'include_export']) {
      const excludedPassword = field.type === 'password' && key !== 'sensitive';
      const target = excludedPassword ? {[key]:false} : field;
      const fallback = key === 'persist' || (key !== 'sensitive' && !field.sensitive);
      const input = control(inspector, t(`property_${key}`), target, key, {type:'boolean'}, {default:fallback,onChange:key === 'sensitive' ? renderInspector : null});
      input.disabled = excludedPassword;
    }
    if (metadata.index_type) {
      control(inspector, t('property_index'), field, 'index', {type:'boolean'}, {onChange:renderInspector});
      if (field.sensitive && field.index) control(inspector, t('property_allow_sensitive_index'), field, 'allow_sensitive_index', {type:'boolean'});
    }
    if (metadata.datatype === 'selection') {
      mountOptionSetPicker(inspector, field, draft.fields, root.dataset.csrf, () => { changed(); renderInspector(); }, data.canResources);
      mountEntitySource(inspector, field, draft.fields, data.form.id, () => { changed(); renderInspector(); });
      if (data.canResources) mountSourceResources(inspector, field, draft.fields, data.providers.fields, root, () => ({id:Number(data.form.id),revision,dirty:dirty || settingsDirty || permissionsDirty}), () => {changed();renderInspector();}, data.canResourceEdit);
      if (field.source) return;
      inspector.append(node('h3', t('options'))); field.options ??= [];
      for (const option of field.options) {
        const row = node('div', undefined, {class: 'nfs-option-row'});
        control(row, t('option_value'), option, 'value', {type: 'string'}, {required: true});
        control(row, t('property_label'), option, 'label', {type: 'string'}, {required: true});
        control(row, t('enabled'), option, 'enabled', {type: 'boolean'}, {default: true});
        control(row, t('property_default'), option, 'default', {type: 'boolean'});
        for (const [key, offset] of [['move_up', -1], ['move_down', 1]]) {
          const index = field.options.indexOf(option), other = index + offset;
          const move = button(t(key), () => { [field.options[index], field.options[other]] = [field.options[other], field.options[index]]; changed(); renderInspector(); });
          move.disabled = other < 0 || other >= field.options.length; row.append(move);
        }
        row.append(button(t('remove'), () => { field.options.splice(field.options.indexOf(option), 1); changed(); renderInspector(); })); inspector.append(row);
      }
      inspector.append(button(t('add_option'), () => { field.options.push({uuid: crypto.randomUUID(), value: '', label: '', enabled: true}); changed(); renderInspector(); }));
    }
  }
  const palette = root.querySelector('[data-nfs-palette]');
  for (const [title, types, isField] of [[t('fields'), Object.keys(data.providers.fields), true], [t('layout'), [...authoringContainers, ...decorations], false]]) {
    const details = node('details'); details.open = isField; details.append(node('summary', title)); const group = node('div', undefined, {class: 'nfs-palette-group'});
    for (const type of types) group.append(button(typeLabel(type, isField), () => {
      const parent = insertionParent(draft, isField ? 'field' : type, selected);
      const uuid = addElement(draft, type, isField, parent);
      if (isField) draft.fields.find(field => field.uuid === uuid).config.label = typeLabel(type, true);
      choose(uuid); changed();
    }));
    details.append(group); palette.append(details);
  }
  async function save() {
    const result = await api('save', {id: Number(data.form.id), revision, draft});
    revision = result.revision; dirty = false; announce(t('saved'));
    root.querySelector('[data-nfs-form-title]').textContent = draft.name;
  }
  function showPreview(result) {
    diagnostics(result.diagnostics);
    const section = root.querySelector('[data-nfs-preview]');
    tabs.select('preview', {focus:true, scroll:true});
    section.querySelector('.nfs-preview-viewport').hidden = result.html === null;
    section.querySelector('[data-nfs-preview-empty]').hidden = result.html !== null;
    if (result.html === null) return;
    const iframe = section.querySelector('iframe');
    iframe.onload = () => {
      const form = iframe.contentDocument?.querySelector('[data-nfs-form]');
      if (form) createInitializer(form => {
        const preview = {id:Number(data.form.id), revision:result.revision, version:result.version_id, locale:result.locale};
        return createFormInstance(form,
          (values, signal) => api('previewOptions', {...preview, values, instances:JSON.parse(form.querySelector('[name="nfs_instances"]')?.value ?? 'null')}, {}, signal),
          async (body, signal) => ({ok:true, ...await api('previewRows', {...preview, ...JSON.parse(body.get('row_change')), instance:form.id, values:JSON.parse(body.get('nfs_values')), instances:JSON.parse(body.get('nfs_instances'))}, {}, signal)}));
      })(form);
    };
    const sheet = new URL('../media/com_nicode_form_studio/css/formstudio.css', location.href).href;
    const locale = /^[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8}){0,3}$/.test(result.locale) ? result.locale : 'en-GB';
    iframe.srcdoc = `<html lang="${locale}"><head><meta charset="utf-8"><link rel="stylesheet" href="${sheet}"></head><body class="nfs-preview-page">${node('h1', result.title ?? draft.name).outerHTML}${result.html}</body></html>`;
  }
  async function loadPermissions(group = 1) {
    const result = await api('permissions', null, {id: Number(data.form.id), group});
    if (result.revision !== revision) throw new Error(t('concurrent_edit'));
    const panel = root.querySelector('[data-nfs-permissions]'); panel.replaceChildren(); permissionsDirty = false;
    const groupLabel = node('label', t('user_group')), select = node('select', undefined, {'aria-label': t('user_group')});
    for (const item of result.groups) select.append(node('option', item.title, {value: item.id}));
    select.value = String(group);
    select.addEventListener('change', async () => {
      if (permissionsDirty && !await confirmAction(t('discard_permission_changes'))) { select.value = String(group); return; }
      run(() => loadPermissions(Number(select.value)));
    }); groupLabel.append(select); panel.append(groupLabel);
    const changes = {};
    for (const [action, state] of Object.entries(result.permissions)) {
      changes[action] = state.direct;
      const label = node('label', t(`permission_${action.replaceAll('.', '_')}`)), setting = node('select');
      for (const [value, key] of [['', 'inherit'], ['1', 'allow'], ['0', 'deny']]) setting.append(node('option', t(`permission_${key}`), {value}));
      setting.value = state.direct === null ? '' : state.direct ? '1' : '0';
      setting.addEventListener('change', () => { changes[action] = setting.value === '' ? null : setting.value === '1'; permissionsDirty = true; announce(t('permissions_unsaved')); });
      label.append(setting, node('span', `${t('effective_permission')}: ${t(state.effective ? 'permission_allow' : 'permission_deny')}`)); panel.append(label);
    }
    panel.append(button(t('apply_permissions'), () => run(async () => {
      if (dirty) await save();
      const updated = await api('applyPermissions', {id: Number(data.form.id), revision, rules_hash: result.rules_hash, group, rules: changes});
      revision = updated.revision; permissionsDirty = false;
      panel.replaceChildren(button(t('load_permissions'), () => run(() => loadPermissions(group))));
      announce(t('permissions_applied'));
    })));
  }
  for (const trigger of actionControls(root, '[data-nfs-command]')) trigger.addEventListener('click', () => run(async () => {
    const id = Number(data.form.id);
    if (trigger.dataset.nfsCommand === 'duplicate') {
      if (settingsDirty || permissionsDirty) { announce(t('duplicate_settings'), true); return; }
      const details = await duplicateFormDetails(draft.name, data.form.alias); if (!details) return;
      if (dirty) await save();
      const result = await api('duplicate', {id, revision, ...details});
      const comment = root.querySelector('[data-nfs-publish-comment]'); if (comment) comment.value = '';
      location.assign(`index.php?option=com_nicode_form_studio&view=editor&id=${result.id}`); return;
    }
    if (trigger.dataset.nfsCommand === 'permissions') { await loadPermissions(); return; }
    if (trigger.dataset.nfsCommand === 'save') { await save(); return; }
    if (trigger.dataset.nfsCommand === 'delete') {
      const review = await api('deletionReview', null, {id});
      if (Number(review.draft_revision) !== revision) throw new Error(t('concurrent_edit'));
      const message = `${t('delete_form_help')}\n${review.name} (${review.uuid})\n${t('submissions')}: ${review.responses}; ${t('files')}: ${review.files}; ${t('delete_bytes')}: ${review.bytes}`;
      if (!await confirmAction(message)) return;
      await api('deletePermanently', {id, revision, confirmation: review.uuid});
      dirty = settingsDirty = permissionsDirty = false;
      const comment = root.querySelector('[data-nfs-publish-comment]'); if (comment) comment.value = '';
      location.assign('index.php?option=com_nicode_form_studio&view=jobs'); return;
    }
    if (trigger.dataset.nfsCommand === 'publish') {
      if (dirty) await save();
      const comment = root.querySelector('[data-nfs-publish-comment]');
      const result = await api('publish', {id, revision, comment: comment?.value ?? ''}); revision = result.revision; data.form.published_version_id = result.version_id; data.form.state = 'published'; data.published_names = Object.fromEntries(draft.fields.map(field => [field.uuid, field.name])); renderInspector(); root.querySelector('[data-nfs-form-state]').textContent = t('state_published'); if (comment) comment.value = ''; diagnostics(result.diagnostics || []); announce(t('published')); return;
    }
    if (['unpublish', 'archive', 'trash'].includes(trigger.dataset.nfsCommand)) {
      const state = {unpublish:'unpublished', archive:'archived', trash:'trashed'}[trigger.dataset.nfsCommand];
      if (state !== 'unpublished' && !await confirmAction(t(`${state}_form_help`))) return;
      const result = await api('deactivate', {id, revision, state}); revision = result.revision; data.form.state = state; root.querySelector('[data-nfs-form-state]').textContent = t(`state_${state}`); const deletion = actionControls(root, '[data-nfs-command="delete"]')[0]; if (deletion) deletion.hidden = state !== 'trashed'; announce(t(`state_${state}`)); return;
    }
    if (trigger.dataset.nfsCommand === 'settings') {
      const settings = {name: draft.name};
      for (const input of root.querySelectorAll('[data-nfs-setting]')) {
        const key = input.dataset.nfsSetting;
        settings[key] = key === 'access' ? Number(input.value) : key.startsWith('publish_') ? (input.value === '' ? null : input.value.replace('T', ' ').padEnd(19, ':00')) : input.value;
      }
      if (dirty) await save();
      const result = await api('settings', {id, revision, settings}); revision = result.revision; Object.assign(data.form, settings); settingsDirty = false; announce(t('settings_applied')); return;
    }
    if (trigger.dataset.nfsCommand === 'preview') {
      if (dirty) await save();
      showPreview(await api('preview', null, {id, locale: translationLocale()}));
      return;
    }
    if (trigger.dataset.nfsCommand === 'history') {
      const result = await api('history', null, {id}); const section = root.querySelector('[data-nfs-versions]'); tabs.select('versions', {focus:true, scroll:true});
      const list = section.querySelector('div'); list.replaceChildren();
      const comparison = node('div', undefined, {class: 'nfs-admin-toolbar'}); const selections = [];
      for (const key of ['compare_from', 'compare_to']) {
        const label = node('label', t(key)), input = node('select');
        input.append(node('option', t('saved_draft'), {value: 0}));
        for (const version of result.versions) input.append(node('option', `${t('version')} ${version.revision}`, {value: version.id}));
        if (key === 'compare_from' && result.versions.length) input.value = result.versions[0].id;
        label.append(input); comparison.append(label); selections.push(input);
      }
      const differences = node('div');
      comparison.append(button(t('compare_versions'), () => run(async () => {
        const diff = await api('compare', null, {id, left: selections[0].value, right: selections[1].value}); differences.replaceChildren();
        if (!diff.changes.length) differences.append(node('p', t('no_differences')));
        else {
          const table = node('table', undefined, {class: 'table'}), header = node('tr');
          for (const key of ['change', 'property_path', 'previous_value', 'new_value']) header.append(node('th', t(key), {scope: 'col'}));
          const head = node('thead'); head.append(header); table.append(head); const body = node('tbody');
          for (const change of diff.changes) { const row = node('tr'); for (const value of [t(`diff_${change.kind}`), change.path, JSON.stringify(change.before), JSON.stringify(change.after)]) row.append(node('td', value)); body.append(row); }
          table.append(body); differences.append(table);
        }
        if (diff.truncated) differences.append(node('p', t('comparison_truncated')));
      }))); list.append(comparison, differences);
      const versionRows = node('div'); list.append(versionRows);
      const appendVersion = version => {
        const row = node('p', `${t('version')} ${version.revision} · ${version.published_at} · ${t('author')} ${version.published_by} · ${version.comment} `);
        row.append(node('span', `${t(`version_${version.version_state}`)}${Number.isInteger(version.submission_count) ? ` · ${t('submission_count')}: ${version.submission_count}` : ''} `));
        row.append(button(t('preview_version'), () => run(async () => { showPreview(await api('preview', null, {id, version: version.id, locale: translationLocale()})); })));
        row.append(button(t('restore'), () => run(async () => {
          if (!await confirmAction(t('restore_help'))) return;
          await api('restore', {id, revision, version_id: Number(version.id)}); dirty = false; settingsDirty = false; permissionsDirty = false;
          const comment = root.querySelector('[data-nfs-publish-comment]'); if (comment) comment.value = ''; location.reload();
        }))); versionRows.append(row);
      };
      for (const version of result.versions) appendVersion(version);
      let before = result.versions.at(-1)?.revision;
      const more = button(t('older_versions'), () => run(async () => {
        const next = await api('history', null, {id, before});
        for (const version of next.versions) { appendVersion(version); for (const input of selections) input.append(node('option', `${t('version')} ${version.revision}`, {value: version.id})); }
        before = next.versions.at(-1)?.revision; more.hidden = next.versions.length < 50;
      })); more.hidden = result.versions.length < 50; list.append(more);
      if (!result.versions.length) list.append(node('p', t('no_versions')));
    }
  }, trigger.dataset.nfsCommand !== 'delete'));
  mountAutomationEditor(root, {draft, providers: data.providers, changed, node, button, control, t, formId: Number(data.form.id), isDirty: () => dirty || settingsDirty || permissionsDirty});
  mountValidatorEditor(root, {draft, providers: data.providers.validators, changed, node, button, control, t});
  const translationEditor = mountTranslationEditor(root, {draft, changed, node, button, t});
  const translationLocale = translationEditor.locale;
  const policy = (owner, key) => owner[key] = configurationObject(owner[key]);
  const choices = values => ({type: 'string', enum: values, enumLabels: Object.fromEntries(values.map(value => [value, t(`choice_${value}`)]))});
  const privacy = policy(draft, 'privacy'), retention = policy(privacy, 'retention'), persistence = policy(draft, 'persistence');
  const privacyPanel = root.querySelector('[data-nfs-privacy]');
  control(privacyPanel, t('persistence_mode'), persistence, 'mode', choices(['full', 'metadata', 'none']));
  control(privacyPanel, t('store_user'), privacy, 'store_user', {type: 'boolean'});
  control(privacyPanel, t('store_ip'), privacy, 'store_ip', {type: 'boolean'});
  control(privacyPanel, t('store_user_agent'), privacy, 'store_user_agent', {type: 'boolean'});
  privacyPanel.append(node('p', t('request_metadata_help')));
  control(privacyPanel, t('retention_action'), retention, 'action', choices(['indefinite', 'delete', 'anonymize']), {onChange: () => { retentionAmount.disabled = (retention.action ?? 'indefinite') === 'indefinite'; }});
  const retentionAmount = control(privacyPanel, t('retention_amount'), retention, 'amount', {type: 'integer', minimum: 1, maximum: 9999});
  retentionAmount.disabled = (retention.action ?? 'indefinite') === 'indefinite';
  control(privacyPanel, t('retention_unit'), retention, 'unit', choices(['days', 'months', 'years']));
  const security = policy(draft, 'security'), captcha = policy(security, 'captcha'), securityPanel = root.querySelector('[data-nfs-security]');
  control(securityPanel, t('captcha_mode'), captcha, 'mode', choices(['inherit', 'joomla', 'provider', 'none']));
  control(securityPanel, t('captcha_provider'), captcha, 'provider', {type: 'string'});
  control(securityPanel, t('honeypot'), security, 'honeypot', {type: 'boolean'}, {default: true});
  for (const [key, minimum, maximum] of [['minimum_seconds', 0, 3600], ['attempt_lifetime', 1, 86400], ['rate_limit', 1, 1000000], ['rate_window', 1, 86400]]) control(securityPanel, t(key), security, key, {type: 'integer', minimum, maximum});
  const post = policy(draft, 'post_submit'), messages = policy(post, 'messages'), postPanel = root.querySelector('[data-nfs-after-submit]');
  control(postPanel, t('success_behavior'), post, 'behavior', choices(['keep', 'hide', 'reset']));
  control(postPanel, t('show_reference'), post, 'show_reference', {type: 'boolean'}, {default: true});
  for (const key of messageCodes) control(postPanel, t(`message_${key}`), messages, key, {type: 'string'}, {multiline: true});
  for (const [property, labelKey] of [['preserve', 'preserve_fields'], ['summary_fields', 'summary_fields']]) {
  const preservedFields = node('fieldset');
  postPanel.append(preservedFields);
  const renderPreservedFields = () => {
    preservedFields.replaceChildren(node('legend', t(labelKey)), node('p', t(labelKey + '_help')));
    const selected = post[property] ?? [];
    if (!Array.isArray(selected) || selected.some(value => typeof value !== 'string')) {
      preservedFields.append(node('p', t('invalid_request')), button(t('remove'), () => { post[property] = []; changed(); renderPreservedFields(); }));
      return;
    }
    const eligible = draft.fields.filter(field => !field.sensitive && !['password', 'file', 'multiple-files'].includes(field.type));
    for (const uuid of new Set([...selected, ...eligible.map(field => field.uuid)])) {
      const field = draft.fields.find(field => field.uuid === uuid);
      const allowed = eligible.some(field => field.uuid === uuid);
      const choice = node('label'), input = node('input', undefined, {type: 'checkbox'});
      input.checked = selected.includes(uuid);
      input.disabled = !allowed && !input.checked;
      const title = field?.config?.label || field?.name || uuid;
      choice.append(input, node('span', allowed ? title : `${t('unavailable')}: ${title}`));
      input.addEventListener('change', () => {
        const values = new Set(post[property] ?? []);
        if (input.checked && allowed) values.add(uuid); else values.delete(uuid);
        post[property] = [...values]; changed();
        input.disabled = !allowed && !input.checked;
      });
      preservedFields.append(choice);
    }
    if (!eligible.length && !selected.length) preservedFields.append(node('p', t(labelKey + '_empty')));
  };
  postPanel.closest('[data-nfs-tab-panel]').addEventListener('nfs:panelshown', renderPreservedFields);
  renderPreservedFields();
  }
  renderTree(); renderInspector();
  if (data.form.state === 'deleting') {
    for (const control of root.querySelectorAll('button,input,select,textarea')) {
      if (control.dataset.nfsCommand !== 'delete') control.disabled = true;
    }
  }
}
