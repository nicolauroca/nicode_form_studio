import {configurationObject} from './builder-model.js';

/** Field references are selected explicitly; provider configuration remains data. */
export function mountValidatorEditor(root, {draft, providers, changed, node, button, control, t}) {
  const host = root.querySelector('[data-nfs-validators]'); if (!host) return;
  const title = id => { const key = `validator_${id}`, value = t(key); return value === `COM_NICODE_FORM_STUDIO_${key.toUpperCase()}` ? id : value; };
  const fieldName = field => field.config?.label || field.name || field.uuid;
  const panel = root.querySelector('[data-nfs-validator-panel]');
  function redraw() {
    host.replaceChildren(node('p', t('validator_help')));
    const groups = [[draft, t('validator_form_scope')], ...draft.fields.filter(field => field.validators?.length).map(field => [field, fieldName(field)])];
    for (const [owner, scope] of groups) {
      for (const [index, validator] of (owner.validators ?? []).entries()) {
        const path = owner === draft ? `/validators/${index}` : `/fields/${draft.fields.indexOf(owner)}/validators/${index}`;
        const card = node('fieldset', undefined, {class: 'nfs-automation-card', 'data-nfs-diagnostic-path': path}); card.append(node('legend', `${scope}: ${title(validator.type)} ${index + 1}`));
        validator.config = configurationObject(validator.config);
        const schema = providers[validator.type]?.configuration_schema;
        if (!schema?.properties) card.append(node('p', t('validator_unavailable')));
        for (const [key, property] of Object.entries(schema?.properties ?? {})) {
          if (property.type === 'array' && property.items?.format === 'field-uuid') {
            const list = Array.isArray(validator.config[key]) ? validator.config[key] : [];
            const box = node('fieldset'); box.append(node('legend', t('validator_fields')));
            for (const [position, uuid] of list.entries()) {
              const label = node('label', `${t('validator_field')} ${position + 1}`), select = node('select', undefined, {'aria-label': `${t('validator_field')} ${position + 1}`});
              select.append(node('option', t('none'), {value: ''}));
              for (const field of draft.fields) select.append(node('option', fieldName(field), {value: field.uuid}));
              if (uuid && !draft.fields.some(field => field.uuid === uuid)) select.append(node('option', `${t('unavailable')}: ${uuid}`, {value: uuid}));
              select.value = uuid;
              select.addEventListener('change', () => { list[position] = select.value; validator.config[key] = list; changed(); });
              label.append(select); box.append(label);
              if (!property.maxItems || list.length > property.minItems) box.append(button(t('remove'), () => { list.splice(position, 1); validator.config[key] = list; changed(); redraw(); }));
            }
            if (!property.maxItems || list.length < property.maxItems) box.append(button(t('validator_add_field'), () => { list.push(draft.fields.find(field => !list.includes(field.uuid))?.uuid ?? ''); validator.config[key] = list; changed(); redraw(); }));
            card.append(box);
          } else if (['string', 'integer', 'boolean', 'array'].includes(property.type)) {
            control(card, key === 'count' ? t('validator_count') : key, validator.config, key, property, {required: schema.required?.includes(key)});
          } else card.append(node('p', `${t('validator_unavailable')}: ${key}`));
        }
        for (const [key, offset] of [['move_up', -1], ['move_down', 1]]) {
          const target = index + offset, move = button(t(key), () => { [owner.validators[index], owner.validators[target]] = [owner.validators[target], owner.validators[index]]; changed(); redraw(); });
          move.disabled = target < 0 || target >= owner.validators.length; card.append(move);
        }
        card.append(button(t('validator_remove'), () => { owner.validators.splice(index, 1); changed(); redraw(); })); host.append(card);
      }
    }
    for (const [id, provider] of Object.entries(providers)) {
      if (provider.scope !== 'cross-field' || !provider.configuration_schema?.properties) continue;
      host.append(button(`${t('validator_add')}: ${title(id)}`, () => {
        const config = {};
        for (const [key, property] of Object.entries(provider.configuration_schema.properties)) {
          if (property.type === 'array' && property.items?.format === 'field-uuid') config[key] = Array.from({length: property.minItems ?? 1}, (_, index) => draft.fields[index]?.uuid ?? '');
          else if (property.default !== undefined) config[key] = structuredClone(property.default);
          else if (provider.configuration_schema.required?.includes(key) && property.type === 'integer') config[key] = property.minimum ?? 0;
        }
        (draft.validators ??= []).push({type: id, config}); changed(); redraw();
      }));
    }
  }
  // Reopening reflects field additions, renames and deletions from the canvas.
  panel?.addEventListener('nfs:panelshown', () => { if (!panel.hidden) redraw(); }); redraw();
}
