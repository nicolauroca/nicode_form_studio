const uuid = value => typeof value === 'string' && value.length === 36 && /^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(value);

export function fieldAddress(field, instances = []) {
  if (!uuid(field) || !Array.isArray(instances) || instances.length > 64) throw new TypeError('Invalid field address.');
  const groups = new Set(), segments = [];
  for (const entry of instances) {
    if (!entry || Array.isArray(entry) || Object.keys(entry).length !== 2 || !Object.hasOwn(entry, 'group') || !Object.hasOwn(entry, 'instance') || !uuid(entry.group) || !uuid(entry.instance)
      || groups.has(entry.group) || entry.group === field) throw new TypeError('Invalid repeated instance address.');
    groups.add(entry.group); segments.push(entry.group, entry.instance);
  }
  return [...segments, field].join('/');
}

export function parseFieldAddress(key) {
  if (typeof key !== 'string' || key.length > 4772) throw new TypeError('Invalid field address.');
  const segments = key.split('/');
  if (segments.length % 2 !== 1) throw new TypeError('Invalid field address shape.');
  const field = segments.pop(), instances = [];
  for (let index = 0; index < segments.length; index += 2) instances.push({group:segments[index], instance:segments[index + 1]});
  fieldAddress(field, instances);
  return {field, instances};
}
