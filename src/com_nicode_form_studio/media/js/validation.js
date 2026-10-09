import { compareDecimal, normalizeDecimal, stepMatches } from './decimal.js';
import { safePattern, evaluateOperator } from './rules.js';
import {browserCall} from './browser-providers.js';
import {temporalKey, temporalTypes} from './temporal.js';

// Supplemental PHP email policy checks. Quoted local parts and address
// literals retain their server-authoritative grammar; this is not a mail parser.
function emailPolicyAllows(value) {
  const text = String(value), at = text.lastIndexOf('@');
  if (at < 1 || /[\r\n\x80-\uffff]/.test(text)) return false;
  const local = text.slice(0, at), domain = text.slice(at + 1);
  if (!local.includes('"') && (local.length > 64 || text.length > 254 || local.startsWith('.') || local.endsWith('.') || local.includes('..'))) return false;
  if (domain.startsWith('[') && domain.endsWith(']')) return true;
  const labels = domain.split('.');
  return labels.length >= 2 && labels.every(label => label.length <= 63 && /^[a-z0-9]+(?:-+[a-z0-9]+)*$/i.test(label))
    && /^(?:[a-z][a-z0-9]*|xn--[a-z0-9]+)(?:-+[a-z0-9]+)*$/i.test(labels.at(-1));
}

// HTML email syntax excludes quoted local parts and address literals accepted
// by the authoritative PHP validator. Only that one native error may defer;
// required, pattern, custom and all other native constraints still apply.
export function nativeConstraintInvalid(field, value, control, providers = {}) {
  if (control.disabled || control.checkValidity()) return false;
  if (field.type !== 'email' || providers.fields?.email || typeof value !== 'string'
    || !(value.includes('"') || /@\[[^\]]*\]$/.test(value))) return true;
  const validity = control.validity;
  return !validity?.typeMismatch || ['badInput', 'customError', 'patternMismatch', 'rangeOverflow', 'rangeUnderflow', 'stepMismatch', 'tooLong', 'tooShort', 'valueMissing'].some(key => validity[key]);
}

export {normalizeField} from './field-normalization.js';

export function validateField(field, value, datatype, state, providers = {}) {
  if (!state.active) return [];
  if (providers.fields?.[field.type]) {
    const errors = browserCall(providers.fields[field.type], 'validate', value, {...field.config, required:state.required}, datatype, state);
    if (!Array.isArray(errors) || errors.some(error => typeof error !== 'string')) throw new TypeError('Invalid field validation result.');
    return errors;
  }
  const config = {...field.config}, errors = [];
  if (field.type === 'range') for (const [key, value] of Object.entries({min:'0',max:'100',step:'1'})) config[key] ??= value;
  const empty = value == null || value === '' || (Array.isArray(value) && value.length === 0);
  if (state.required && (empty || (datatype === 'boolean' && value !== true))) return ['required'];
  if (empty) return errors;
  if (field.type === 'email' && !emailPolicyAllows(value)) errors.push('email');
  if (field.type === 'color' && !/^#[0-9a-fA-F]{6}$/.test(value)) errors.push('color');
  // Native URL validity checks syntax; these enforce the server's protocol and
  // ASCII policies as well, without rewriting the visitor's canonical value.
  if (field.type === 'url' && (!/^https?:\/\//i.test(value) || /[^\x21-\x7e]/.test(value))) errors.push('url');
  if (datatype === 'file') {
    const receipts = Array.isArray(value) ? value : [value];
    if (receipts.length > (config.max_files ?? 1)) errors.push('file_count');
    for (const receipt of receipts) {
      if (receipt.size > config.max_bytes) errors.push('file_size');
      if (!(config.extensions || []).includes(receipt.name.split('.').pop().toLowerCase())) errors.push('file_type');
    }
    return [...new Set(errors)];
  }
  if (Array.isArray(value)) {
    if (config.min_selections != null && value.length < config.min_selections) errors.push('min_selections');
    if (config.max_selections != null && value.length > config.max_selections) errors.push('max_selections');
  } else if (datatype !== 'boolean') {
    const text = String(value), length = [...text].length;
    if (config.min_length != null && length < config.min_length) errors.push('min_length');
    if (config.max_length != null && length > config.max_length) errors.push('max_length');
    if (config.pattern && !safePattern(config.pattern).test(text)) errors.push('pattern');
    if (['integer', 'decimal'].includes(datatype)) {
      if (config.min != null && compareDecimal(text, String(config.min)) < 0) errors.push('min');
      if (config.max != null && compareDecimal(text, String(config.max)) > 0) errors.push('max');
      if (config.step != null && !stepMatches(text, String(config.step), String(config.min ?? '0'))) errors.push('step');
      if (config.scale != null && (normalizeDecimal(text).split('.')[1] || '').length > config.scale) errors.push('scale');
      if (config.precision != null && normalizeDecimal(text).replace(/[-.]/g, '').length > config.precision) errors.push('precision');
    }
  }
  if (datatype === 'selection') {
    const allowed = state.options.filter(option => option.enabled ?? true).map(option => option.value);
    if ((Array.isArray(value) ? value : [value]).some(item => !allowed.includes(item))) errors.push('option');
  }
  if (temporalTypes.includes(datatype)) {
    const key = temporalKey(datatype, value);
    if (key === null) errors.push(datatype);
    else for (const bound of ['min', 'max']) {
      if (config[bound] == null) continue;
      const limit = temporalKey(datatype, config[bound]);
      if (limit === null || (bound === 'min' ? key < limit : key > limit)) errors.push(bound);
    }
  }
  for (const item of Array.isArray(value) ? value : [value]) {
    if (field.index_type === 'keyword' && [...String(item)].length > 255) errors.push('index_length');
    if (['date', 'datetime'].includes(field.index_type) && (temporalKey(field.index_type, item) === null || String(item).slice(0, 4) < '1000')) errors.push('index_date');
    if (field.index_type === 'decimal') {
      const [integer, fraction = ''] = normalizeDecimal(String(item)).replace(/^-/, '').split('.');
      if (integer.length > 26 || fraction.length > 12) errors.push('index_precision');
    }
  }
  return errors;
}

export function validateRelations(spec, values, datatypes, states, providers = {}, deferredFields = new Set()) {
  const definitions = [...(spec.validators || []), ...spec.fields.filter(field => states[field.uuid]?.active).flatMap(field => field.validators || [])], errors = {};
  const present = value => value != null && value !== '' && value !== false && (!Array.isArray(value) || value.length > 0);
  for (const definition of definitions) {
    if (definition.server_only) continue;
    if (Array.isArray(definition.config?.fields) && definition.config.fields.some(uuid => deferredFields.has(uuid))) continue;
    if (providers.validators?.[definition.type]) {
      const violations = browserCall(providers.validators[definition.type], 'validate', values, definition.config || {}, datatypes);
      if (!violations || typeof violations !== 'object' || Array.isArray(violations)) throw new TypeError('Invalid validator result.');
      for (const [uuid, codes] of Object.entries(violations)) {
        if (!Array.isArray(codes) || codes.some(code => typeof code !== 'string')) throw new TypeError('Invalid validator codes.');
        if (Object.hasOwn(values, uuid)) (errors[uuid] ||= []).push(...codes);
      }
      continue;
    }
    const config = definition.config || {}, fields = config.fields || [], active = fields.filter(uuid => Object.hasOwn(values, uuid));
    if (!active.length) continue;
    let valid;
    if (['at_least_one', 'exactly_n'].includes(definition.type)) {
      const count = active.filter(uuid => present(values[uuid])).length;
      valid = definition.type === 'at_least_one' ? count >= 1 : count === config.count;
    } else {
      const empty = value => value == null || value === '' || (Array.isArray(value) && value.length === 0);
      if (active.length !== fields.length || empty(values[fields[0]]) || empty(values[fields[1]])) continue;
      const operator = {confirmation: 'equals', range: 'less_equal'}[definition.type] || definition.type;
      valid = evaluateOperator(operator, values[fields[0]], values[fields[1]], datatypes[fields[0]]);
    }
    if (!valid) for (const uuid of active) (errors[uuid] ||= []).push(`cross.${definition.type}`);
  }
  return errors;
}
