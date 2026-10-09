import {packSubmissionValues} from './submission-data.js';
import {parseFieldAddress} from './field-address.js';
import {loadBrowserProviders, loadBrowserStyles} from './browser-providers.js';
import {RemoteOptions, remoteOptionInputs} from './remote-options.js';

// A row edit never uploads file bytes. The live controls remain in their form.
export function rowRequestData(form, change) {
  const body = new URLSearchParams();
  for (const [key, value] of packSubmissionValues(new FormData(form))) if (typeof value === 'string') body.append(key, value);
  body.set('row_change', change); body.set('format', 'json');
  return body;
}

export function retainedRowNodes(previousFields, nextFields, previousNodes, nextNodes) {
  const previous = new Map(previousFields.map(field => [field.uuid, field.type]));
  const seen = new Set(), retained = [];
  for (const field of nextFields) {
    parseFieldAddress(field.uuid);
    if (seen.has(field.uuid) || !nextNodes.has(field.uuid)) throw new TypeError('Invalid row field graph.');
    seen.add(field.uuid);
    if (previous.has(field.uuid)) {
      if (previous.get(field.uuid) !== field.type || !previousNodes.has(field.uuid)) throw new TypeError('Changed row field type.');
      retained.push([nextNodes.get(field.uuid), previousNodes.get(field.uuid)]);
    }
  }
  return retained;
}

export function updateRowButtons(instance) {
  if (!instance.spec.instances) return;
  for (const button of instance.form.querySelectorAll('[data-nfs-row-change]')) {
    const change = JSON.parse(button.value), group = instance.spec.elements.find(element => element.uuid === change.group);
    const count = instance.spec.instances?.[change.group]?.length;
    button.disabled = instance.pending || instance.configurationError || (instance.form.dataset.nfsPreview === 'true' && !instance.previewRows) || !group || !Number.isInteger(count)
      || (change.operation === 'add' ? count >= group.repeat.max : count <= group.repeat.min);
  }
}

async function applyRowResponse(instance, html, change) {
  const form = instance.form, parsed = new DOMParser().parseFromString(html, 'text/html');
  const next = parsed.querySelector('form[data-nfs-form]');
  if (!next || next.id !== form.id || parsed.querySelectorAll('form').length !== 1) throw new TypeError('Invalid row form.');
  for (const name of ['form_id','version_id','attempt','channel','instance']) {
    if (next.elements.namedItem(name)?.value !== form.elements.namedItem(name)?.value) throw new TypeError('Changed row form identity.');
  }
  const script = next.querySelector('[data-nfs-definition]'), spec = JSON.parse(script.textContent);
  if (!Array.isArray(spec.fields) || !Array.isArray(spec.elements) || !spec.instances || typeof spec.instances !== 'object') throw new TypeError('Invalid row graph.');
  const nextNodes = new Map([...next.querySelectorAll('[data-nfs-element]')].map(node => [node.dataset.nfsElement,node]));
  const retained = retainedRowNodes(instance.spec.fields, spec.fields, instance.nodes, nextNodes);
  const elements = next.querySelector('.nfs-elements'), liveElements = form.querySelector('.nfs-elements');
  const declarations = next.elements.namedItem('nfs_instances');
  if (!elements || !liveElements || !declarations || JSON.stringify(JSON.parse(declarations.value)) !== JSON.stringify(spec.instances)) throw new TypeError('Invalid row declarations.');
  const providers = await loadBrowserProviders(spec.browser_providers || {});
  await loadBrowserStyles(spec.browser_providers || {}, form.ownerDocument);
  if (!form.isConnected) return;
  const previousStep = instance.steps?.[instance.currentStep]?.dataset.nfsStep;
  const initial = instance.initialValues, priorRows = instance.spec.instances[change.group] || [];
  // Move the original nodes, never clone them: files, widget listeners and input
  // changes made while the request was in flight must survive.
  for (const [placeholder, original] of retained) placeholder.replaceWith(original);
  for (const element of spec.elements.filter(element => element.type === 'captcha')) {
    const original = instance.nodes.get(element.uuid), placeholder = nextNodes.get(element.uuid);
    if (original && placeholder) placeholder.replaceWith(original);
  }
  instance.remoteOptions.dispose();
  liveElements.replaceChildren(...elements.childNodes);
  const hidden = form.elements.namedItem('nfs_instances'); hidden.value = declarations.value; hidden.defaultValue = declarations.value;
  form.querySelector('[data-nfs-definition]').textContent = script.textContent;
  instance.spec = spec; instance.providers = providers;
  instance.nodes = new Map([...form.querySelectorAll('[data-nfs-element]')].map(node => [node.dataset.nfsElement,node]));
  instance.controls = new Map(spec.fields.map(field => [field.uuid,[...form.querySelectorAll(`[data-nfs-input="${CSS.escape(field.uuid)}"]`)]]));
  instance.initialValues = new Map(spec.fields.map(field => [field.uuid,initial.has(field.uuid) ? initial.get(field.uuid) : instance.read(field)]));
  instance.errors = Object.fromEntries(Object.entries(instance.errors).filter(([key]) => instance.nodes.has(key)));
  instance.steps = [...form.querySelectorAll('[data-nfs-step]')];
  instance.currentStep = Math.max(0,instance.steps.findIndex(step => step.dataset.nfsStep === previousStep));
  instance.remoteOptions = new RemoteOptions(form,spec.fields,()=>instance.refresh(),instance.message.bind(instance),instance.previewOptions,remoteOptionInputs(spec));
  instance.refresh(); instance.showErrors(instance.errors,false);
  instance.result.textContent = instance.message('rows_updated','Entries updated.');
  const added = (spec.instances[change.group] || []).find(row => !priorRows.includes(row));
  const group = instance.nodes.get(change.group);
  const target = added ? [...form.querySelectorAll('[data-nfs-repeat-row]')].find(node => node.dataset.nfsRepeatRow === added && node.dataset.nfsRepeatScope === change.group) : group;
  const control = target?.querySelector('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])');
  if (control && !control.closest('[hidden]')) control.focus();
  else if (target && !target.closest('[hidden]')) { target.tabIndex = -1; target.focus(); }
  else instance.result.focus();
}

export async function editRow(instance, button) {
  if (instance.pending || instance.configurationError || (instance.form.dataset.nfsPreview === 'true' && !instance.previewRows) || button.disabled) return;
  const form = instance.form, controller = new AbortController();
  const timeout = setTimeout(()=>controller.abort(),30000);
  instance.pending = true; form.setAttribute('aria-busy','true'); updateRowButtons(instance);
  form.querySelector('[data-nfs-submit]').disabled = true;
  instance.result.textContent = instance.message('rows_updating','Updating entries…');
  try {
    const change = JSON.parse(button.value);
    let result;
    if (form.dataset.nfsPreview === 'true') {
      result = await instance.previewRows(rowRequestData(form,button.value),controller.signal);
    } else {
    const url = new URL(form.action,form.ownerDocument.baseURI);
    url.searchParams.set('task','form.rows'); url.searchParams.set('format','json');
    const response = await fetch(url,{method:'POST',body:rowRequestData(form,button.value),credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{Accept:'application/json'}});
    result = await response.json();
    if (!response.ok) throw new Error('rows_failed');
    }
    if (result?.ok !== true || typeof result.form?.html !== 'string') {
      instance.result.textContent = instance.message(result?.error || 'rows_failed',instance.message('rows_failed','Entries could not be updated. Please retry.'));
      instance.result.focus(); return;
    }
    await applyRowResponse(instance,result.form.html,change);
  } catch {
    instance.result.textContent = instance.message('rows_failed','Entries could not be updated. Please retry.'); instance.result.focus();
  } finally {
    clearTimeout(timeout); instance.pending = false; form.removeAttribute('aria-busy');
    updateRowButtons(instance);
    form.querySelector('[data-nfs-submit]').disabled = form.dataset.nfsPreview === 'true' || instance.configurationError || instance.remoteOptions.pending || instance.remoteOptions.failed;
  }
}
