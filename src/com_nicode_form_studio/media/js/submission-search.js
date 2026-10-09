import {confirmAction} from './confirm-action.js';
import {correlationControl} from './search-correlation.js';
const t = key => Joomla.Text._(`COM_NICODE_FORM_STUDIO_${key.toUpperCase()}`);
const labelControl = (parent, text, control) => { const label = document.createElement('label'); control.setAttribute('aria-label', text); label.append(document.createTextNode(text), control); parent.append(label); return control; };
const select = entries => {
  const control = document.createElement('select');
  for (const [value, text] of entries) { const option = document.createElement('option'); option.value = value; option.textContent = text; control.append(option); }
  return control;
};
for (const form of document.querySelectorAll('[data-nfs-search]')) {
  const fields = JSON.parse(form.querySelector('[data-nfs-filter-schema]').textContent);
  const hidden = form.elements.field_filters; const initial = JSON.parse(hidden.value);
  const rows = []; const container = form.querySelector('[data-nfs-field-filters]');
  function add(filter = {}) {
    if (!container || fields.length === 0 || rows.length >= 50) return;
    const row = document.createElement('div'); row.className = 'nfs-admin-filters';
    const field = labelControl(row, t('field'), select(fields.map(item => [item.uuid, item.label])));
    field.value = fields.some(item => item.uuid === filter.field) ? filter.field : fields[0].uuid;
    const operator = labelControl(row, t('operator'), select([])); const values = document.createElement('div'); values.className = 'nfs-admin-filters'; row.append(values);
    const entry = {row, field, operator, inputs: [], type: null}; rows.push(entry);
    const correlationHost = document.createElement('div'); row.append(correlationHost);
    const correlationInput = labelControl(correlationHost, t('same_instance'), document.createElement('input'));
    const correlationHelp = document.createElement('small'); correlationHelp.textContent = t('same_instance_help'); correlationHost.append(correlationHelp);
    entry.correlation = correlationControl(correlationInput, filter.same_instance, t('same_instance_unavailable'));
    const drawValues = (existing = []) => {
      values.replaceChildren(); entry.inputs = [];
      for (let i = 0; i < (operator.value === 'between' ? 2 : 1); i++) {
        const control = entry.type === 'boolean' ? select([['1', t('true')], ['0', t('false')]]) : document.createElement('input');
        if (control.tagName === 'INPUT') {
          control.type = ({integer: 'number', decimal: 'number', date: 'date', datetime: 'datetime-local'})[entry.type] ?? 'text';
          control.step = entry.type === 'decimal' ? 'any' : '1'; control.maxLength = 2048; control.required = true;
        }
        control.value = String(existing[i] ?? (entry.type === 'boolean' ? '1' : '')).replace(entry.type === 'datetime' ? ' ' : '\u0000', 'T');
        entry.inputs.push(labelControl(values, t(i === 0 ? 'filter_value' : 'filter_upper'), control));
      }
    };
    const drawField = () => {
      const definition = fields.find(item => item.uuid === field.value); entry.type = definition.index_type;
      correlationHost.hidden = !entry.correlation.update(definition.same_instance);
      operator.replaceChildren(...select(definition.operators.map(value => [value, t(`operator_${value}`)])).children);
      drawValues();
    };
    field.addEventListener('change', drawField); operator.addEventListener('change', () => drawValues()); drawField();
    if (fields.find(item => item.uuid === field.value).operators.includes(filter.operator)) operator.value = filter.operator;
    drawValues(Array.isArray(filter.value) ? filter.value : [filter.value]);
    const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-secondary'; remove.textContent = t('remove');
    remove.addEventListener('click', () => { rows.splice(rows.indexOf(entry), 1); row.remove(); }); row.append(remove); container.append(row);
  }
  for (const filter of initial) add(filter);
  form.querySelector('[data-nfs-add-filter]')?.addEventListener('click', () => add());
  form.elements.form_id.addEventListener('change', () => { rows.splice(0); container?.replaceChildren(); hidden.value = '[]'; for (const column of form.querySelectorAll('[name="columns[]"]')) column.checked = false; form.requestSubmit(); });
  const syncFilters = () => {
    hidden.value = JSON.stringify(rows.map(entry => {
      const values = entry.inputs.map(input => entry.type === 'datetime' ? input.value.replace('T', ' ').padEnd(19, ':00') : input.value);
      return entry.correlation.apply({field: entry.field.value, operator: entry.operator.value, value: entry.operator.value === 'between' ? values : values[0]});
    }));
  };
  form.addEventListener('submit', syncFilters);
  const root = form.closest('section');
  let busy = false;
  const write = async (task, payload) => {
    const url = new URL('index.php', location.href); url.search = new URLSearchParams({option: 'com_nicode_form_studio', task: `submission.${task}`, format: 'json'});
    const response = await fetch(url, {method: 'POST', credentials: 'same-origin', cache: 'no-store', body: new URLSearchParams({[root.dataset.csrf]: '1', payload: JSON.stringify(payload)})});
    let result;
    try { result = await response.json(); } catch { throw new Error(t('session_error')); }
    if (!response.ok || !result.ok) throw new Error(t(result.error || 'unexpected_error'));
    return result.data;
  };
  const run = async operation => {
    if (busy) return; busy = true;
    try { await operation(); } catch (error) { root.querySelector('[data-nfs-search-status]').textContent = error.message; }
    finally { busy = false; }
  };
  root.querySelector('[data-nfs-save-view]').addEventListener('submit', event => {
    event.preventDefault(); if (!form.reportValidity()) return; syncFilters();
    const name = event.currentTarget.elements.name.value; const filters = {};
    for (const key of ['id', 'uuid', 'form_id', 'state', 'channel', 'action_status', 'received_from', 'received_to', 'user_id']) {
      const value = form.elements[key].value; if (value !== '') filters[key] = value;
    }
    const columns = [...form.querySelectorAll('[name="columns[]"]:checked')].map(input => input.value);
    run(async () => {
      const result = await write('saveView', {name, query: {filters, fields: JSON.parse(hidden.value), columns, sort: form.elements.sort.value}});
      const url = new URL('index.php', location.href); url.search = new URLSearchParams({option: 'com_nicode_form_studio', view: 'submissions', preset: String(result.id)}); location.assign(url);
    });
  });
  for (const button of root.querySelectorAll('[data-nfs-remove-view]')) {
    button.addEventListener('click', async () => { if (!await confirmAction(t('remove_view_confirm'))) return; run(async () => { await write('removeView', {id: Number(button.dataset.nfsRemoveView)}); if (new URL(location.href).searchParams.get('preset') === button.dataset.nfsRemoveView) location.assign('index.php?option=com_nicode_form_studio&view=submissions'); else button.closest('li').remove(); }); });
  }
}
