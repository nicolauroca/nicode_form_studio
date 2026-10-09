export function mountPrefillEditor(host, field, fields, metadata, {node, control, changed, redraw, t}) {
  if (!metadata.prefill) return;
  const box = node('fieldset'); box.append(node('legend', t('prefill')));
  const label = node('label', t('prefill_mode')), select = node('select', undefined, {'aria-label': t('prefill_mode'), 'data-nfs-prefill-mode': ''});
  const modes = ['none', 'constant', 'user', 'context', 'query', 'field', ...(metadata.datatype === 'selection' ? ['source'] : [])];
  for (const mode of modes) select.append(node('option', t(`prefill_${mode}`), {value: mode}));
  select.value = field.prefill?.type ?? 'none';
  select.addEventListener('change', () => {
    if (select.value === 'none') delete field.prefill;
    else field.prefill = {type: select.value, ...(select.value === 'constant' ? {value: field.config.default ?? (metadata.multiple ? [] : metadata.datatype === 'boolean' ? false : '')} : select.value === 'user' ? {property: 'email'} : select.value === 'field' ? {field: fields.find(item => item.uuid !== field.uuid)?.uuid ?? ''} : ['query', 'context'].includes(select.value) ? {key: ''} : {})};
    changed(); redraw(); host.querySelector('[data-nfs-prefill-mode]')?.focus();
  }); label.append(select); box.append(label, node('p', t('prefill_help')));
  const prefill = field.prefill;
  if (prefill?.type === 'constant') control(box, t('prefill_value'), prefill, 'value', {type: metadata.multiple ? 'array' : metadata.datatype === 'boolean' ? 'boolean' : 'string'}, {required: true});
  if (prefill?.type === 'user') control(box, t('prefill_property'), prefill, 'property', {type: 'string', enum: ['id', 'name', 'username', 'email']}, {required: true});
  if (['context', 'query'].includes(prefill?.type)) control(box, t('prefill_key'), prefill, 'key', {type: 'string'}, {required: true});
  if (prefill?.type === 'field') {
    const names = Object.fromEntries(fields.filter(item => item.uuid !== field.uuid).map(item => [item.uuid, item.config?.label || item.name]));
    if (prefill.field && !names[prefill.field]) names[prefill.field] = `${t('unavailable')}: ${prefill.field}`;
    control(box, t('prefill_field_reference'), prefill, 'field', {type: 'string', enum: Object.keys(names), enumLabels: names}, {required: true});
  }
  host.append(box);
}
