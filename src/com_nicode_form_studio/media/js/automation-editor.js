import {mountEmailEditor} from './email-editor.js';
/** Visual, typed condition/effect and action authoring; no executable configuration. */
import {configurationObject} from './builder-model.js';
import {mountEmailTemplatePicker} from './templates.js';
import {coreBrowserProviders} from './browser-providers.js';
import {mountAttachmentFields} from './attachment-fields-editor.js';
export function mountAutomationEditor(root, {draft, providers, changed, node, button, control, t, formId, isDirty}) {
  for (const action of draft.actions) action.config = configurationObject(action.config);
  const logic = root.querySelector('[data-nfs-logic]'), actions = root.querySelector('[data-nfs-actions]');
  const fieldName = field => field.config?.label || field.name || field.uuid;
  const metadata = uuid => {
    const field = providers.fields[draft.fields.find(candidate => candidate.uuid === uuid)?.type] ?? {};
    const extra = Object.entries(providers.operators || {}).filter(([id, operator]) => !coreBrowserProviders.operators.includes(id) && Array.isArray(operator.datatypes) && operator.datatypes.includes(field.datatype)).map(([id]) => id);
    return {...field, operators:[...new Set([...(field.operators || []), ...extra])]};
  };
  const defaultValue = uuid => metadata(uuid).multiple ? [] : metadata(uuid).datatype === 'boolean' ? false : '';
  const freshCondition = () => {
    const field = draft.fields[0]?.uuid ?? '';
    return {field, operator: metadata(field).operators?.[0] ?? 'equals', value: defaultValue(field)};
  };
  function select(host, title, value, choices, update) {
    const label = node('label', title), input = node('select', undefined, {'aria-label': title});
    for (const [id, text] of choices) input.append(node('option', text, {value: id}));
    if (!choices.some(([id]) => id === value)) input.append(node('option', `${t('unavailable')}: ${value}`, {value}));
    input.value = value; input.addEventListener('change', () => { update(input.value); changed(); });
    label.append(input); host.append(label); return input;
  }
  function valueControl(host, object, uuid, operator = 'equals') {
    if (['empty', 'not_empty'].includes(operator)) return;
    const provider = metadata(uuid);
    const list = ['in', 'not_in', 'between', 'filter_options'].includes(operator) || (provider.multiple && ['equals', 'not_equals', 'set_value', 'change_default'].includes(operator));
    control(host, t(list ? 'values_list' : 'condition_value'), object, 'value', {type: list ? 'array' : provider.datatype === 'boolean' ? 'boolean' : 'string'}, {required: true});
  }
  function condition(host, current, replace, remove = null, depth = 0) {
    const box = node('fieldset', undefined, {class: 'nfs-condition'});
    box.append(node('legend', t(current.group ? 'condition_group' : 'condition')));
    if (depth > 32) { box.append(node('p', t('nesting_limit'))); host.append(box); return; }
    if (current.group) {
      select(box, t('match'), current.group, [['AND', t('match_all')], ['OR', t('match_any')]], value => { current.group = value; });
      for (const child of current.children ?? []) condition(box, child, next => { current.children[current.children.indexOf(child)] = next; redraw(); }, () => { current.children.splice(current.children.indexOf(child), 1); changed(); redraw(); }, depth + 1);
      box.append(button(t('add_condition'), () => { current.children.push(freshCondition()); changed(); redraw(); }));
      box.append(button(t('add_group'), () => { current.children.push({group: 'AND', children: [freshCondition()]}); changed(); redraw(); }));
    } else {
      select(box, t('condition_field'), current.field ?? '', draft.fields.map(field => [field.uuid, fieldName(field)]), value => {
        current.field = value; current.operator = metadata(value).operators?.[0] ?? 'equals'; current.value = defaultValue(value); redraw();
      });
      select(box, t('operator'), current.operator ?? '', (metadata(current.field).operators ?? []).map(id => [id, t(`operator_${id}`)]), value => {
        current.operator = value;
        if (['in', 'not_in', 'between'].includes(value)) current.value = [];
        else if (['empty', 'not_empty'].includes(value)) delete current.value;
        else current.value = defaultValue(current.field);
        redraw();
      });
      valueControl(box, current, current.field, current.operator);
      box.append(button(t('wrap_group'), () => { replace({group: 'AND', children: [current]}); changed(); redraw(); }));
    }
    if (remove) box.append(button(t('remove'), remove));
    host.append(box);
  }
  function options(host, effect) {
    if (!Array.isArray(effect.value)) effect.value = [];
    for (const option of effect.value) {
      const row = node('div', undefined, {class: 'nfs-option-row'});
      control(row, t('option_value'), option, 'value', {type: 'string'}, {required: true});
      control(row, t('property_label'), option, 'label', {type: 'string'}, {required: true});
      row.append(button(t('remove'), () => { effect.value.splice(effect.value.indexOf(option), 1); changed(); redraw(); })); host.append(row);
    }
    host.append(button(t('add_option'), () => { effect.value.push({uuid: crypto.randomUUID(), value: '', label: ''}); changed(); redraw(); }));
  }
  function renderLogic() {
    logic.replaceChildren();
    for (const [index, rule] of draft.rules.entries()) {
      const card = node('fieldset', undefined, {class: 'nfs-automation-card', 'data-nfs-diagnostic-path': `/rules/${index}`}); card.append(node('legend', `${t('rule')} ${index + 1}`));
      control(card, t('enabled'), rule, 'enabled', {type: 'boolean'}, {default: true});
      control(card, t('priority'), rule, 'priority', {type: 'integer'}, {required: true});
      condition(card, rule.when, next => { rule.when = next; });
      card.append(node('h3', t('effects')));
      for (const effect of rule.effects) {
        const box = node('fieldset', undefined, {class: 'nfs-condition'}); box.append(node('legend', t('effect')));
        select(box, t('effect'), effect.type, Object.keys(providers.effects).map(id => [id, t(`effect_${id}`)]), value => {
          effect.type = value;
          if (['change_options', 'filter_options'].includes(value)) effect.value = [];
          else if (['set_value', 'change_default'].includes(value)) effect.value = defaultValue(effect.target);
          else delete effect.value;
          redraw();
        });
        const targets = ['show', 'hide'].includes(effect.type) ? draft.elements : draft.elements.filter(element => element.type === 'field');
        select(box, t('target'), effect.target, targets.map(element => [element.uuid, draft.fields.find(field => field.uuid === element.uuid)?.config?.label || element.title || element.type]), value => { effect.target = value; redraw(); });
        if (effect.type === 'change_options') options(box, effect);
        else if (['set_value', 'change_default', 'filter_options'].includes(effect.type)) valueControl(box, effect, effect.target, effect.type);
        box.append(button(t('remove'), () => { rule.effects.splice(rule.effects.indexOf(effect), 1); changed(); redraw(); })); card.append(box);
      }
      card.append(button(t('add_effect'), () => { rule.effects.push({type: 'show', target: draft.elements[0]?.uuid ?? ''}); changed(); redraw(); }));
      card.append(button(t('remove_rule'), () => { draft.rules.splice(index, 1); changed(); redraw(); })); logic.append(card);
    }
    logic.append(button(t('add_rule'), () => { draft.rules.push({uuid: crypto.randomUUID(), enabled: true, priority: draft.rules.length, when: freshCondition(), effects: []}); changed(); redraw(); }));
  }
  const maps = new WeakMap();
  function mapping(host, configuration, key) {
    let fields = maps.get(configuration); if (!fields) { fields = new Map(); maps.set(configuration, fields); }
    if (!fields.has(key)) fields.set(key, Object.entries(configuration[key] ?? {}).map(([name, value]) => ({name, value})));
    const rows = fields.get(key); const inputs = new Map(); const box = node('fieldset'); box.append(node('legend', t(`action_${key}`)));
    const commit = () => {
      const entries = rows.filter(row => row.name !== '').map(row => [row.name, row.value]);
      configuration[key] = Object.fromEntries(entries); changed();
      for (const [row, input] of inputs) input.setCustomValidity(rows.some(other => other !== row && other.name === row.name) ? t('duplicate_key') : '');
    };
    for (const row of rows) {
      const item = node('div', undefined, {class: 'nfs-map-row'});
      const name = control(item, t('map_key'), row, 'name', {type: 'string'}, {required: true, onChange: () => {
        name.setCustomValidity(rows.some(other => other !== row && other.name === row.name) ? t('duplicate_key') : ''); commit();
      }});
      name.required = true;
      inputs.set(row, name);
      if (rows.some(other => other !== row && other.name === row.name)) name.setCustomValidity(t('duplicate_key'));
      control(item, t('map_value'), row, 'value', {type: 'string'}, {required: true, onChange: commit});
      item.append(button(t('remove'), () => { rows.splice(rows.indexOf(row), 1); commit(); redraw(); })); box.append(item);
    }
    box.append(button(t('add_mapping'), () => { rows.push({name: '', value: ''}); changed(); redraw(); })); host.append(box);
  }
  function renderActions() {
    actions.replaceChildren();
    for (const [index, action] of draft.actions.entries()) {
      const card = node('fieldset', undefined, {class: 'nfs-automation-card', 'data-nfs-diagnostic-path': `/actions/${index}`}); card.append(node('legend', `${t(`action_type_${action.type}`)} ${index + 1}`));
      control(card, t('enabled'), action, 'enabled', {type: 'boolean'}, {default: true});
      control(card, t('order'), action, 'order', {type: 'integer'}, {required: true});
      select(card, t('failure_policy'), action.failure_policy ?? 'non_blocking', (providers.actions[action.type]?.failure_policies ?? []).map(id => [id, t(id)]), value => { action.failure_policy = value; });
      action.config ??= {};
      if (['email_notification', 'email_autoresponse'].includes(action.type)) mountEmailTemplatePicker(card, {root, draft, action, formId, isDirty, changed, redraw});
      for (const [key, schema] of Object.entries(providers.actions[action.type]?.configuration_schema?.properties ?? {})) {
        if (['email_notification', 'email_autoresponse'].includes(action.type) && ['subject','body_text','body_html','email_format'].includes(key)) continue;
        if (schema.attachment_fields) { mountAttachmentFields(card, action.config, draft.fields, {node, button, t, changed}); continue; }
        if (schema.type === 'object') { mapping(card, action.config, key); continue; }
        if (schema.row_selection) {
          select(card, t(`action_${key}`), action.config[key] ?? '', [['', t('none')], ...schema.enum.map(value => [value, t(`row_selection_${value}`)])], value => { if (value) action.config[key] = value; else delete action.config[key]; });
        } else if (schema.field_type) {
          select(card, t(`action_${key}`), action.config[key] ?? '', [['', t('none')], ...draft.fields.filter(field => field.type === schema.field_type).map(field => [field.uuid, fieldName(field)])], value => { if (value) action.config[key] = value; else delete action.config[key]; });
        } else control(card, t(`action_${key}`), action.config, key, schema, {multiline: schema.multiline});
        if (schema.secret_reference) card.append(node('p', t('secret_reference_help')));
      }
      if (['email_notification', 'email_autoresponse'].includes(action.type)) mountEmailEditor(card, {config:action.config, fields:draft.fields, node, button, control, t, changed});
      if (action.condition) {
        condition(card, action.condition, next => { action.condition = next; });
        card.append(button(t('remove_condition'), () => { delete action.condition; changed(); redraw(); }));
      } else card.append(button(t('add_condition'), () => { action.condition = freshCondition(); changed(); redraw(); }));
      card.append(button(t('remove_action'), () => { draft.actions.splice(index, 1); changed(); redraw(); })); actions.append(card);
    }
    const add = node('div', undefined, {class: 'nfs-admin-toolbar'});
    for (const type of Object.keys(providers.actions)) add.append(button(`${t('add_action')}: ${t(`action_type_${type}`)}`, () => {
      draft.actions.push({uuid: crypto.randomUUID(), type, enabled: true, order: draft.actions.length, failure_policy: 'non_blocking', config: {}}); changed(); redraw();
    })); actions.append(add);
  }
  function renderConditionalMessages() {
    const host = root.querySelector('[data-nfs-conditional-messages]'); host.replaceChildren();
    host.append(node('p', t('conditional_messages_help')));
    const messages = draft.post_submit?.conditional_messages ?? [];
    for (const [index, message] of messages.entries()) {
      const card = node('fieldset', undefined, {class: 'nfs-automation-card'}); card.append(node('legend', `${t('conditional_message')} ${index + 1}`));
      condition(card, message.condition, next => { message.condition = next; });
      control(card, t('text'), message, 'message', {type: 'string'}, {multiline: true, required: true});
      for (const [key, direction] of [['move_up', -1], ['move_down', 1]]) {
        const other = index + direction;
        const move = button(t(key), () => { [messages[index], messages[other]] = [messages[other], messages[index]]; changed(); redraw(); });
        move.disabled = other < 0 || other >= messages.length; card.append(move);
      }
      card.append(button(t('remove'), () => {
        messages.splice(index, 1);
        for (const translation of Object.values(draft.translations ?? {})) { if (translation.conditional_messages) delete translation.conditional_messages[message.uuid]; }
        changed(); redraw();
      })); host.append(card);
    }
    host.append(button(t('add_conditional_message'), () => {
      draft.post_submit = configurationObject(draft.post_submit); draft.post_submit.conditional_messages ??= [];
      draft.post_submit.conditional_messages.push({uuid: crypto.randomUUID(), condition: freshCondition(), message: ''}); changed(); redraw();
    }));
  }
  function redraw() { renderLogic(); renderActions(); renderConditionalMessages(); }
  for (const selector of ['[data-nfs-logic-panel]', '[data-nfs-actions-panel]', '[data-nfs-conditional-panel]']) root.querySelector(selector).addEventListener('nfs:panelshown', redraw);
  redraw();
}
