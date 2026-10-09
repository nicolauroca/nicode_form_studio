/** Explicit file opt-in; missing or excluded selections remain visible for repair. */
export function mountAttachmentFields(host, configuration, fields, {node, button, t, changed}) {
  const box = node('fieldset');
  box.append(node('legend', t('action_attachment_fields')), node('p', t('attachment_fields_help')));
  const render = () => {
    const list = node('div');
    const selected = configuration.attachment_fields ?? [];
    if (!Array.isArray(selected) || selected.some(value => typeof value !== 'string')) {
      list.append(node('p', t('attachment_fields_invalid')), button(t('remove'), () => { configuration.attachment_fields = []; changed(); redraw(); }));
      return list;
    }
    const eligible = fields.filter(field => ['file', 'multiple-files'].includes(field.type) && (field.include_email ?? !field.sensitive));
    const choices = [...new Set([...selected, ...eligible.map(field => field.uuid)])];
    if (!choices.length) list.append(node('p', t('attachment_fields_empty')));
    const inputs = [];
    for (const uuid of choices) {
      const field = fields.find(candidate => candidate.uuid === uuid);
      const allowed = eligible.some(candidate => candidate.uuid === uuid);
      const label = node('label');
      const input = node('input', undefined, {type:'checkbox'});
      input.checked = selected.includes(uuid);
      const name = field?.config?.label || field?.name || uuid;
      label.append(input, node('span', allowed ? name : `${t('unavailable')}: ${name}`));
      inputs.push({input, uuid, allowed});
      input.addEventListener('change', () => {
        const current = configuration.attachment_fields ?? [];
        if (input.checked && allowed && current.length < 20) configuration.attachment_fields = [...current.filter(value => value !== uuid), uuid];
        else if (!input.checked) configuration.attachment_fields = current.filter(value => value !== uuid);
        else input.checked = current.includes(uuid);
        changed(); refresh();
      });
      list.append(label);
    }
    function refresh() {
      const current = configuration.attachment_fields ?? [];
      for (const {input, uuid, allowed} of inputs) {
        input.checked = current.includes(uuid);
        input.disabled = !input.checked && (!allowed || current.length >= 20);
      }
    }
    refresh(); return list;
  };
  let list;
  function redraw() { const next = render(); if (list) list.replaceWith(next); else box.append(next); list = next; }
  redraw(); host.append(box);
}
