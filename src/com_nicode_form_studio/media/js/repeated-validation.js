/** Validate public per-scope row counts after browser rule evaluation. */
export function repeatedErrors(spec, states) {
  if (spec.instances === undefined) return {};
  if (!spec.instances || typeof spec.instances !== 'object' || Array.isArray(spec.instances)) throw new TypeError('Invalid instance declarations.');
  const errors = {};
  for (const element of spec.elements) {
    if (element.type !== 'repeatable-group') continue;
    const rows = spec.instances[element.uuid], limits = element.repeat, state = states[element.uuid];
    if (!Array.isArray(rows) || !limits || !Number.isInteger(limits.min) || !Number.isInteger(limits.max) || limits.min < 0 || limits.max < limits.min || typeof state?.active !== 'boolean') throw new TypeError('Invalid repeated group state.');
    if (rows.length > limits.max) throw new TypeError('Maximum repetitions exceeded.');
    if (state.active && rows.length < limits.min) errors[element.uuid] = ['min_instances'];
  }
  return errors;
}
