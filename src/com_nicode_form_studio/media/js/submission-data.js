// One JSON part avoids PHP max_input_vars truncation. Files and the trusted
// CSRF/CAPTCHA/request envelope remain ordinary multipart parts.
import {parseFieldAddress} from './field-address.js';

export function packSubmissionValues(data) {
  const values = Object.create(null), names = new Set(), files = [];
  for (const [name, value] of data.entries()) {
    const match = /^nfs\[([^\[\]]+)\](\[\])?$/.exec(name);
    if (!match) continue;
    parseFieldAddress(match[1]);
    if (typeof value !== 'string') { files.push([name, value]); continue; }
    const uuid = match[1]; names.add(name);
    if (match[2]) {
      if (Object.hasOwn(values, uuid) && !Array.isArray(values[uuid])) throw new TypeError('Mixed field multiplicity.');
      (values[uuid] ||= []).push(value);
    } else {
      if (Array.isArray(values[uuid])) throw new TypeError('Mixed field multiplicity.');
      values[uuid] = value;
    }
  }
  for (const name of names) data.delete(name);
  // FormData.delete removes every part with this name, including files supplied
  // alongside a string by a custom control. Restore only those affected files.
  for (const [name, file] of files) if (names.has(name)) data.append(name, file);
  data.set('nfs_values', JSON.stringify(values));
  return data;
}
