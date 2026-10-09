const t = key => Joomla.Text._(`COM_NICODE_FORM_STUDIO_${key.toUpperCase()}`);
const element = (tag, text, attributes = {}) => {
  const node = document.createElement(tag); if (text !== undefined) node.textContent = text;
  for (const [key, value] of Object.entries(attributes)) node.setAttribute(key, value);
  return node;
};
const button = (text, action) => {
  const node = element('button', text, {type: 'button', class: 'btn btn-secondary'});
  node.addEventListener('click', action); return node;
};
export async function optionSetApi(csrf, operation, payload = null, query = {}) {
  const url = new URL('index.php', location.href);
  url.search = new URLSearchParams({option: 'com_nicode_form_studio', task: `optionset.${operation}`, format: 'json', ...query});
  const request = {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}};
  if (payload !== null) { request.method = 'POST'; request.body = new URLSearchParams({payload: JSON.stringify(payload), [csrf]: '1'}); }
  const response = await fetch(url, request); let result;
  try { result = await response.json(); } catch { throw new Error(t('session_error')); }
  if (!response.ok || !result.ok) throw new Error(t(result.error || 'unexpected_error'));
  return result.data;
}
export function mountOptionSetPicker(host, field, fields, csrf, changed, allowed) {
  const box = element('fieldset'); box.append(element('legend', t('option_sets'))); host.append(box);
  if (field.source) {
    box.append(element('p', field.source.type === 'option_set' ? `${t('resource_pinned')}: ${field.source.config?.resource_uuid ?? ''} · ${t('resource_revision')} ${field.source.config?.revision ?? ''}` : `${t('entity_source')}: ${field.source.type}`));
    box.append(button(t('resource_detach'), () => { delete field.source; changed(); }));
  }
  if (!allowed) return;
  box.append(element('p', t('resource_load_help')));
  const status = element('p', '', {role: 'status', 'aria-live': 'polite'}); box.append(status);
  let busy = false, before, loaded = false;
  const run = async action => {
    if (busy) return; busy = true; box.disabled = true;
    try { await action(); } catch (error) { status.textContent = error.message; }
    finally { busy = false; box.disabled = false; }
  };
  const select = element('select', undefined, {'aria-label': t('option_sets')});
  const selectLabel = element('label', t('option_sets')); selectLabel.append(select);
  const revision = element('input', undefined, {type: 'number', min: '1', step: '1', 'aria-label': t('resource_revision')});
  const revisionLabel = element('label', t('resource_revision')); revisionLabel.append(revision);
  const dependencies = element('div'); let record = null;
  const reset = () => { record = null; dependencies.replaceChildren(); };
  select.addEventListener('change', () => { revision.value = select.selectedOptions[0]?.dataset.revision ?? ''; reset(); });
  revision.addEventListener('input', reset);
  const load = button(t('resource_load'), () => run(async () => {
    const result = await optionSetApi(csrf, 'listing', null, before ? {before} : {});
    if (!box.isConnected) return;
    for (const row of result.rows) {
      const option = element('option', `${row.name} (#${row.id})`, {value: row.id, 'data-revision': row.revision});
      if (Number(row.revision) < 1) option.disabled = true; select.append(option);
    }
    before = result.next_before; load.hidden = before === null; load.textContent = t('resource_more');
    if (!loaded) { loaded = true; box.append(selectLabel, revisionLabel, inspect, dependencies); revision.value = select.selectedOptions[0]?.dataset.revision ?? ''; }
    if (!select.value) status.textContent = t('resource_select');
  }));
  const inspect = button(t('resource_inspect'), () => run(async () => {
    reset();
    if (!select.value || !Number.isSafeInteger(Number(revision.value)) || Number(revision.value) < 1) throw new Error(t('resource_select'));
    const result = await optionSetApi(csrf, 'record', null, {id: select.value, revision: revision.value});
    if (!box.isConnected) return; record = result;
    const parameters = [...new Set(result.snapshot.options.flatMap(option => Object.keys(option.when ?? {})))];
    const bindings = {};
    for (const parameter of parameters) {
      const label = element('label', `${t('resource_bind')}: ${parameter}`);
      const input = element('select', undefined, {'aria-label': `${t('resource_bind')}: ${parameter}`});
      input.append(element('option', t('resource_select'), {value: ''}));
      for (const candidate of fields) if (candidate.uuid !== field.uuid) input.append(element('option', candidate.config?.label || candidate.name, {value: candidate.uuid}));
      input.addEventListener('change', () => { bindings[parameter] = input.value; }); label.append(input); dependencies.append(label);
    }
    dependencies.append(element('p', `${result.snapshot.name} · ${t('resource_revision')} ${result.snapshot.revision} · ${result.snapshot.options.length} ${t('options')}`));
    dependencies.append(button(t('resource_apply'), () => run(async () => {
      if (!record || parameters.some(parameter => !bindings[parameter])) throw new Error(t('invalid_request'));
      const source = await optionSetApi(csrf, 'source', {id: Number(record.resource.id), revision: Number(record.snapshot.revision), bindings});
      if (!box.isConnected) return; field.source = source; changed();
    })));
  }));
  box.append(load);
}
for (const root of document.querySelectorAll('[data-nfs-resources]')) {
  const status = root.querySelector('[data-nfs-resource-status]'); let busy = false, dirty = false;
  const changed = () => { dirty = true; status.textContent = t('unsaved'); };
  window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
  const run = async action => {
    if (busy) return; busy = true; root.setAttribute('aria-busy', 'true');
    const controls = [...root.querySelectorAll('input,select,button,textarea')].map(node => [node, node.disabled]);
    for (const [node] of controls) node.disabled = true;
    try { await action(); } catch (error) { status.textContent = error.message; }
    finally { for (const [node, disabled] of controls) node.disabled = disabled; busy = false; root.removeAttribute('aria-busy'); }
  };
  root.querySelector('[data-nfs-resource-create]')?.addEventListener('submit', event => {
    event.preventDefault(); const name = new FormData(event.target).get('name');
    run(async () => { const result = await optionSetApi(root.dataset.csrf, 'create', {name}); location.assign(`index.php?option=com_nicode_form_studio&view=optionset&id=${result.id}`); });
  });
  if (!root.hasAttribute('data-nfs-optionset')) continue;
  const data = JSON.parse(root.querySelector('[data-nfs-resource-data]').textContent);
  const options = data.snapshot.options; const host = root.querySelector('[data-nfs-option-rows]');
  const conditionDrafts = new WeakMap();
  const input = (host, title, value, update, attributes = {}) => {
    const label = element('label', title); const node = element('input', undefined, attributes);
    if (attributes.type === 'checkbox') node.checked = value; else node.value = value;
    node.addEventListener('input', () => { update(attributes.type === 'checkbox' ? node.checked : node.value); changed(); });
    label.append(node); host.append(label); return node;
  };
  function render() {
    host.replaceChildren();
    options.forEach((option, index) => {
      const row = element('fieldset', undefined, {class: 'nfs-option-row'}); row.append(element('legend', `${t('options')} ${index + 1}`));
      input(row, t('option_value'), option.value, value => { option.value = value; }, {required: '', maxlength: '255'});
      input(row, t('property_label'), option.label, value => { option.label = value; }, {maxlength: '16384'});
      input(row, t('enabled'), option.enabled, value => { option.enabled = value; }, {type: 'checkbox'});
      input(row, t('property_default'), option.default, value => { option.default = value; }, {type: 'checkbox'});
      const dependencies = element('div'); row.append(element('p', t('resource_dependencies_help')), dependencies);
      const conditions = conditionDrafts.get(option) ?? Object.entries(option.when).map(([parameter, value]) => ({parameter, value}));
      conditionDrafts.set(option, conditions);
      const validateParameters = () => {
        const seen = new Set();
        for (const node of dependencies.querySelectorAll('[data-parameter]')) { node.setCustomValidity(seen.has(node.value) ? t('resource_duplicate_parameter') : ''); seen.add(node.value); }
      };
      const sync = () => {
        option.when = Object.fromEntries(conditions.map(condition => [condition.parameter, condition.value]));
        validateParameters();
        changed();
      };
      function renderConditions() {
        dependencies.replaceChildren();
        for (const condition of conditions) {
          const box = element('div', undefined, {class: 'nfs-option-row'});
          input(box, t('resource_parameter'), condition.parameter, value => { condition.parameter = value; sync(); }, {required: '', pattern: '[a-z][a-z0-9_]{0,63}', 'data-parameter': ''});
          const type = element('select', undefined, {'aria-label': t('resource_value_type')});
          for (const value of ['string', 'integer', 'boolean', 'null']) type.append(element('option', t(`resource_type_${value}`), {value}));
          condition.kind ??= condition.value === null ? 'null' : typeof condition.value === 'number' ? 'integer' : typeof condition.value;
          type.value = condition.kind;
          const typeLabel = element('label', t('resource_value_type')); typeLabel.append(type); box.append(typeLabel);
          const valueHost = element('span'); box.append(valueHost);
          const renderValue = () => {
            valueHost.replaceChildren(); if (type.value === 'null') return;
            const valueInput = input(valueHost, t('option_value'), condition.raw ?? condition.value, value => {
              if (type.value === 'integer') { condition.raw = value; const number = Number(value); valueInput.setCustomValidity(value !== '' && Number.isSafeInteger(number) ? '' : t('invalid_request')); if (!valueInput.checkValidity()) return; condition.value = number; }
              else condition.value = value;
              sync();
            }, type.value === 'boolean' ? {type: 'checkbox'} : type.value === 'integer' ? {type: 'number', step: '1', required: ''} : {});
            if (type.value === 'integer') valueInput.setCustomValidity(valueInput.value !== '' && Number.isSafeInteger(Number(valueInput.value)) ? '' : t('invalid_request'));
          };
          type.addEventListener('change', () => { condition.kind = type.value; delete condition.raw; condition.value = ({string: '', integer: 0, boolean: false, null: null})[type.value]; renderValue(); sync(); }); renderValue();
          box.append(button(t('remove'), () => { conditions.splice(conditions.indexOf(condition), 1); renderConditions(); sync(); })); dependencies.append(box);
        }
        validateParameters();
      }
      renderConditions();
      row.append(button(t('resource_add_dependency'), () => { conditions.push({parameter: '', value: ''}); renderConditions(); sync(); }));
      for (const [label, offset] of [['move_up', -1], ['move_down', 1]]) {
        const move = button(t(label), () => { const other = index + offset; [options[index], options[other]] = [options[other], options[index]]; changed(); render(); });
        move.disabled = index + offset < 0 || index + offset >= options.length; row.append(move);
      }
      row.append(button(t('remove'), () => { options.splice(index, 1); changed(); render(); })); host.append(row);
    });
  }
  render();
  root.querySelector('[data-nfs-option-add]')?.addEventListener('click', () => { options.push({uuid: crypto.randomUUID(), value: '', label: '', enabled: true, default: false, when: {}, metadata: {}}); changed(); render(); host.lastElementChild?.querySelector('input')?.focus(); });
  root.querySelector('[name=name]').addEventListener('input', changed);
  root.querySelector('[data-nfs-resource-edit]').addEventListener('submit', event => {
    event.preventDefault(); if (!data.can_edit) return;
    const name = new FormData(event.target).get('name');
    run(async () => { await optionSetApi(root.dataset.csrf, 'save', {id: Number(data.resource.id), revision: Number(data.resource.revision), name, options}); dirty = false; location.assign(`index.php?option=com_nicode_form_studio&view=optionset&id=${data.resource.id}`); });
  });
}
