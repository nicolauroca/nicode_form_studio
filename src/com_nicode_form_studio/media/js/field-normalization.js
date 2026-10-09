import {compareDecimal, normalizeDecimal} from './decimal.js';
import {browserCall} from './browser-providers.js';

export function normalizeField(field, raw, datatype, providers = {}) {
  if (providers.fields?.[field.type]) return browserCall(providers.fields[field.type], 'normalize', raw, field.config || {}, datatype);
  if (datatype === 'file') {
    const files = raw == null ? [] : raw;
    if (!Array.isArray(files)) throw new TypeError('Expected file list.');
    const receipts = files.map(file => ({name: file.name, size: file.size, mime: file.type || ''}));
    return field.type === 'multiple-files' ? receipts : receipts[0] || null;
  }
  if (['multiselect', 'checkbox-group'].includes(field.type)) {
    if (raw == null || raw === '') return [];
    if (!Array.isArray(raw) || raw.some(value => typeof value !== 'string' && !(typeof value === 'number' && Number.isSafeInteger(value)))) throw new TypeError('Expected scalar selection list.');
    return [...new Set(raw.map(String))];
  }
  if (raw == null || raw === '') return null;
  if (datatype === 'boolean') {
    if ([true, 1, '1', 'true', 'on'].includes(raw)) return true;
    if ([false, 0, '0', 'false', 'off'].includes(raw)) return false;
    throw new TypeError('Invalid boolean.');
  }
  if (typeof raw !== 'string' && !(typeof raw === 'number' && Number.isSafeInteger(raw))) throw new TypeError('Expected scalar text.');
  const value = datatype !== 'selection' && field.type !== 'password' && (field.config?.trim ?? true) ? String(raw).replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '') : String(raw);
  if (value.isWellFormed && !value.isWellFormed()) throw new TypeError('Invalid Unicode.');
  if (value === '') return null;
  if (['integer', 'decimal'].includes(datatype)) {
    const number = normalizeDecimal(value);
    if (datatype === 'integer' && number.includes('.')) throw new TypeError('Invalid integer.');
    if (datatype === 'integer' && (compareDecimal(number, '-9223372036854775808') < 0 || compareDecimal(number, '9223372036854775807') > 0)) throw new TypeError('Integer outside signed 64-bit range.');
    return number;
  }
  return value;
}

