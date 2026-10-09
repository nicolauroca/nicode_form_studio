import { compareDecimal } from './decimal.js';
import {browserCall} from './browser-providers.js';
import {temporalKey, temporalTypes} from './temporal.js';

const empty = value => value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0);
import {normalizeField} from './field-normalization.js';
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const equal = (a, b, datatype) => ['integer', 'decimal'].includes(datatype) && a != null && b != null
  ? compareDecimal(String(a), String(b)) === 0 : same(a, b);
const compare = (a, b, datatype) => {
  if (a == null || b == null || Array.isArray(a) || Array.isArray(b)) throw new TypeError('Invalid comparison operands.');
  return ['integer', 'decimal'].includes(datatype) ? compareDecimal(String(a), String(b)) : String(a) < String(b) ? -1 : String(a) > String(b) ? 1 : 0;
};

export function safePattern(pattern) {
  const fail = () => { throw new TypeError('Unsupported pattern.'); };
  if (typeof pattern !== 'string' || pattern.length > 512 || pattern.includes('~') || /[^\x20-\x7e]/.test(pattern)) fail();
  let body = pattern.startsWith('^') ? pattern.slice(1) : pattern;
  if (body.endsWith('$') && !body.endsWith('\\$')) body = body.slice(0, -1);
  const atom = /(?:\\[dws.\[\]{}+*?^$\\-]|\[(?:\\[\\\]\-]|[^\]\\])+\]|[^()|\[\]{}+*?^$\\])/y;
  const quantifier = /([+*?]|\{([0-9]+)(?:,([0-9]*))?\})/y;
  let offset = 0, variable = 0;
  while (offset < body.length) {
    atom.lastIndex = offset;
    const token = atom.exec(body);
    if (!token) fail();
    offset += token[0].length;
    quantifier.lastIndex = offset;
    const repeat = quantifier.exec(body);
    if (repeat) {
      offset += repeat[0].length;
      if (repeat[0].length === 1) variable++;
      else {
        const min = Number(repeat[2]), range = repeat[0].includes(',');
        const max = range && repeat[3] !== '' ? Number(repeat[3]) : min;
        if (min > 1000 || max > 1000 || max < min) fail();
        if (range && (repeat[3] === '' || min !== max)) variable++;
      }
      if (variable > 1) fail();
    }
  }
  const expanded = pattern.replace(/\\./g, token => ({'\\d':'[0-9]', '\\w':'[A-Za-z0-9_]', '\\s':'[ \\t\\r\\n\\f\\v]', '\\-':'\\x2d'})[token] || token);
  return new RegExp(`^(?:${expanded})$(?![\\s\\S])`, 'u');
}

export function evaluateOperator(operator, left, right, datatype, providers = {}) {
  if (providers.operators?.[operator]) {
    const result = browserCall(providers.operators[operator], 'evaluate', left, right, datatype);
    if (typeof result !== 'boolean') throw new TypeError('Operator must return boolean.');
    return result;
  }
  if (operator === 'empty') return empty(left);
  if (operator === 'not_empty') return !empty(left);
  if (!empty(left) && temporalTypes.includes(datatype)) {
    left = temporalKey(datatype, left);
    if (left === null) return false;
    const operands = (Array.isArray(right) ? right : [right]).map(value => temporalKey(datatype, value));
    if (operands.some(value => value === null)) return false;
    right = Array.isArray(right) ? operands : operands[0];
  }
  if (operator === 'equals') return equal(left, right, datatype);
  if (operator === 'not_equals') return !equal(left, right, datatype);
  if (empty(left)) return false;
  switch (operator) {
    case 'contains': return typeof left === 'string' && typeof right === 'string' && left.includes(right);
    case 'not_contains': return typeof left === 'string' && typeof right === 'string' && !left.includes(right);
    case 'starts_with': return typeof left === 'string' && typeof right === 'string' && left.startsWith(right);
    case 'ends_with': return typeof left === 'string' && typeof right === 'string' && left.endsWith(right);
    case 'in': return Array.isArray(right) && right.some(value => equal(left, value, datatype));
    case 'not_in': return Array.isArray(right) && !right.some(value => equal(left, value, datatype));
    case 'selected': return Array.isArray(left) ? left.some(value => same(value, right)) : same(left, right);
    case 'not_selected': return Array.isArray(left) ? !left.some(value => same(value, right)) : !same(left, right);
    case 'greater': case 'after': return compare(left, right, datatype) > 0;
    case 'less': case 'before': return compare(left, right, datatype) < 0;
    case 'greater_equal': return compare(left, right, datatype) >= 0;
    case 'less_equal': return compare(left, right, datatype) <= 0;
    case 'between': return Array.isArray(right) && right.length === 2 && compare(left, right[0], datatype) >= 0 && compare(left, right[1], datatype) <= 0;
    case 'pattern': return typeof left === 'string' && typeof right === 'string' && safePattern(right).test(left);
    default: throw new TypeError('Required operator unavailable.');
  }
}

export function matches(condition, values, datatypes, depth = 0, providers = {}) {
  if (depth > 64) throw new TypeError('Condition nesting exceeds safe depth.');
  if (condition.group) {
    if (!['AND', 'OR'].includes(condition.group) || !condition.children?.length) throw new TypeError('Invalid condition group.');
    const predicate = child => matches(child, values, datatypes, depth + 1, providers);
    return condition.group === 'AND' ? condition.children.every(predicate) : condition.children.some(predicate);
  }
  if (!Object.hasOwn(datatypes, condition.field)) throw new TypeError('Unknown condition field.');
  return evaluateOperator(condition.operator, values[condition.field] ?? null, condition.value ?? null, datatypes[condition.field], providers);
}

export function applyEffect(state, effect, providers = {}) {
  if (providers.effects?.[effect.type]) {
    const result = browserCall(providers.effects[effect.type], 'apply', state, effect);
    if (!result || typeof result !== 'object' || Array.isArray(result) || ['visible', 'enabled', 'required'].some(key => typeof result[key] !== 'boolean') || !Array.isArray(result.options) || !Object.hasOwn(result, 'value')) throw new TypeError('Effect returned invalid state.');
    return result;
  }
  const next = { ...state };
  switch (effect.type) {
    case 'show': next.visible = true; break;
    case 'hide': next.visible = false; break;
    case 'enable': next.enabled = true; break;
    case 'disable': next.enabled = false; break;
    case 'required': next.required = true; break;
    case 'optional': next.required = false; break;
    case 'set_value': next.value = effect.value; break;
    case 'clear_value': next.value = null; break;
    case 'change_default': if (next.value === null) next.value = effect.value; break;
    case 'change_options': next.options = effect.value; break;
    case 'filter_options': next.options = next.options.filter(option => effect.value.includes(option.value)); break;
    default: throw new TypeError('Required effect unavailable.');
  }
  return next;
}

function inherit(states, parents) {
  for (const [uuid, state] of Object.entries(states)) {
    state.active = state.visible && state.enabled;
    let parent = parents[uuid];
    const seen = new Set([uuid]);
    while (parent !== null) {
      if (seen.has(parent) || !Object.hasOwn(states, parent)) throw new TypeError('Invalid layout ancestry.');
      seen.add(parent);
      state.active = state.active && states[parent].visible && states[parent].enabled;
      parent = parents[parent];
    }
  }
  return states;
}

export function evaluateRules(spec, normalized, datatypes, maxIterations = 64, sourceResolver = null, providers = {}) {
  const baseline = Object.create(null), parents = Object.create(null);
  for (const element of spec.elements) {
    parents[element.uuid] = element.parent_uuid ?? null;
    baseline[element.uuid] = { visible: element.visible ?? true, enabled: true, required: false, value: null, options: [], active: true };
  }
  for (const field of spec.fields) {
    const config = field.config || {}, initial = baseline[field.uuid];
    Object.assign(initial, { visible: config.visible ?? initial.visible, enabled: !(config.disabled ?? false), required: config.required ?? false,
      value: normalized[field.uuid] ?? null, options: field.options || [] });
  }
  const rules = [...spec.rules].sort((a, b) => ((a.priority || 0) - (b.priority || 0)) || (a.uuid < b.uuid ? -1 : a.uuid > b.uuid ? 1 : 0));
  let current = inherit(structuredClone(baseline), parents);
  const seen = new Set();
  for (let iteration = 1; iteration <= maxIterations; iteration++) {
    const fingerprint = JSON.stringify(current);
    if (seen.has(fingerprint)) throw new TypeError('Rule configuration does not converge.');
    seen.add(fingerprint);
    const values = Object.fromEntries(Object.entries(current).map(([uuid, state]) => [uuid, state.active ? state.value : null]));
    let next = structuredClone(baseline);
    const normalizationErrors = {};
    for (const field of spec.fields) {
      if (field.prefill?.type === 'field' && (field.config?.readonly || ['system', 'calculated'].includes(field.type))) {
        try { next[field.uuid].value = normalizeField(field, values[field.prefill.field] ?? null, datatypes[field.uuid], providers); }
        catch { next[field.uuid].value = null; normalizationErrors[field.uuid] = ['type']; }
      }
      if (!field.source) continue;
      if (['static', 'option_set'].includes(field.source.type)) {
        const declared = new Set(field.source.dependencies || []);
        next[field.uuid].options = (field.source.config.options || []).filter(option => (option.enabled ?? true)
          && Object.entries(option.when || {}).every(([uuid, expected]) => declared.has(uuid) && same(values[uuid], expected)))
          .map(option => ({value:option.value, label:option.label, enabled:true, ...(Object.hasOwn(option, 'default') ? {default:option.default} : {})}));
      } else {
        if (!sourceResolver) throw new TypeError('Data source resolver is required.');
        next[field.uuid].options = sourceResolver(field, values);
      }
    }
    const optionDefaults = new Map();
    const defaultValue = (uuid, multiple) => {
      const defaults = next[uuid].options.filter(option => (option.enabled ?? true) && option.default === true).map(option => option.value);
      return multiple ? defaults : (defaults[0] ?? null);
    };
    for (const field of spec.fields) {
      if (!['single', 'multiple'].includes(field.option_defaults)) continue;
      const multiple = field.option_defaults === 'multiple';
      optionDefaults.set(field.uuid, multiple); next[field.uuid].value = defaultValue(field.uuid, multiple);
    }
    for (const rule of rules) {
      if ((rule.enabled ?? true) && matches(rule.when, values, datatypes, 0, providers)) {
        for (const effect of rule.effects) {
          const previous = JSON.stringify(next[effect.target].value);
          next[effect.target] = applyEffect(next[effect.target], effect, providers);
          if (JSON.stringify(next[effect.target].value) !== previous || ['set_value', 'clear_value'].includes(effect.type)) { optionDefaults.delete(effect.target); delete normalizationErrors[effect.target]; }
        }
      }
    }
    for (const [uuid, multiple] of optionDefaults) next[uuid].value = defaultValue(uuid, multiple);
    next = inherit(next, parents);
    if (JSON.stringify(next) === fingerprint) {
      const accepted = Object.fromEntries(spec.fields.filter(field => next[field.uuid].active).map(field => [field.uuid, next[field.uuid].value]));
      return { states: next, values: accepted, iterations: iteration, normalizationErrors };
    }
    current = next;
  }
  throw new TypeError('Rule iteration limit exceeded.');
}
