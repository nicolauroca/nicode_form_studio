/** Layout mutations shared by the visual editor; unknown provider data stays intact. */
export const containers = ['section', 'group', 'fieldset', 'row', 'columns', 'panel', 'step', 'repeatable-group'];
export const authoringContainers = [...containers];
export const decorations = ['heading', 'subheading', 'paragraph', 'notice', 'safe-html', 'separator', 'spacer', 'captcha'];
// PHP represents an empty associative array as []; object properties must remain
// serializable when an incomplete saved configuration is opened again.
export const configurationObject = value => value && typeof value === 'object' ? {...value} : {};

export function descendants(draft, uuid) {
  const found = new Set([uuid]);
  for (let changed = true; changed;) {
    changed = false;
    for (const element of draft.elements) if (found.has(element.parent_uuid) && !found.has(element.uuid)) { found.add(element.uuid); changed = true; }
  }
  return found;
}

export function revealAncestors(draft, uuid, collapsed) {
  const elements = new Map(draft.elements.map(element => [element.uuid, element]));
  const visited = new Set(); let parent = elements.get(uuid)?.parent_uuid;
  while (parent != null && !visited.has(parent)) {
    visited.add(parent); collapsed.delete(parent); parent = elements.get(parent)?.parent_uuid;
  }
}

export function moveElement(draft, uuid, direction) {
  const item = draft.elements.find(element => element.uuid === uuid);
  if (!item || ![-1, 1].includes(direction)) return false;
  const siblings = draft.elements.filter(element => (element.parent_uuid ?? null) === (item.parent_uuid ?? null));
  const index = siblings.indexOf(item), other = siblings[index + direction];
  if (!other) return false;
  const a = draft.elements.indexOf(item), b = draft.elements.indexOf(other);
  [draft.elements[a], draft.elements[b]] = [draft.elements[b], draft.elements[a]];
  return true;
}

export function reparentElement(draft, uuid, parent) {
  const item = draft.elements.find(element => element.uuid === uuid);
  if (!item) throw new Error('Unknown element.');
  if (parent !== null && (!draft.elements.some(element => element.uuid === parent && containers.includes(element.type)) || descendants(draft, uuid).has(parent))) throw new Error('Invalid parent.');
  if (!supportsStepParent(draft, uuid, parent)) throw new Error('A step cannot be inside another step.');
  item.parent_uuid = parent;
}

export function supportsStepParent(draft, uuid, parent) {
  const subtree = descendants(draft, uuid);
  if (!draft.elements.some(element => subtree.has(element.uuid) && element.type === 'step')) return true;
  const visited = new Set();
  while (parent !== null && !visited.has(parent)) {
    visited.add(parent);
    const ancestor = draft.elements.find(element => element.uuid === parent);
    if (!ancestor) break;
    if (ancestor.type === 'step') return false;
    parent = ancestor.parent_uuid ?? null;
  }
  return true;
}

export function insertionParent(draft, type, selected) {
  const item = draft.elements.find(element => element.uuid === selected);
  let parent = item && containers.includes(item.type) ? item.uuid : item?.parent_uuid ?? null;
  if (type !== 'step') return parent;
  const seen = new Set(); let ancestor = parent;
  while (ancestor !== null && !seen.has(ancestor)) {
    seen.add(ancestor);
    const node = draft.elements.find(element => element.uuid === ancestor);
    if (!node) break;
    if (node.type === 'step') parent = node.parent_uuid ?? null;
    ancestor = node.parent_uuid ?? null;
  }
  return parent;
}

export function removeElement(draft, uuid) {
  const removed = descendants(draft, uuid);
  draft.elements = draft.elements.filter(element => !removed.has(element.uuid));
  draft.fields = draft.fields.filter(field => !removed.has(field.uuid));
  // References in rules/actions remain visible to the compiler for deliberate repair.
  return removed;
}

export function dropParent(draft, uuid, target, position) {
  if (!['before','after','inside'].includes(position)) throw new Error('Invalid placement.');
  const item = draft.elements.find(element => element.uuid === uuid);
  const destination = draft.elements.find(element => element.uuid === target);
  if (!item || (target !== null && !destination) || (target === null && position !== 'inside')) throw new Error('Unknown element.');
  if (target !== null && descendants(draft, uuid).has(target)) throw new Error('Invalid descendant destination.');
  const parent = position === 'inside' ? target : destination.parent_uuid ?? null;
  if (parent !== null && !draft.elements.some(element => element.uuid === parent && containers.includes(element.type))) throw new Error('Invalid parent.');
  if (!supportsStepParent(draft, uuid, parent)) throw new Error('A step cannot be inside another step.');
  return parent;
}

export function dropElement(draft, uuid, target, position) {
  const parent = dropParent(draft, uuid, target, position);
  const item = draft.elements.find(element => element.uuid === uuid);
  const remaining = draft.elements.filter(element => element !== item);
  const index = position === 'inside' ? remaining.length : remaining.findIndex(element => element.uuid === target) + (position === 'after' ? 1 : 0);
  item.parent_uuid = parent;
  remaining.splice(index, 0, item); draft.elements = remaining;
}

export function addElement(draft, type, field = false, parent = null, uuid = crypto.randomUUID()) {
  const element = {uuid, type: field ? 'field' : type, parent_uuid: null};
  if (!field && type === 'repeatable-group') element.repeat = {min:1, max:5};
  draft.elements.push(element);
  try { reparentElement(draft, uuid, parent); } catch (error) { draft.elements.pop(); throw error; }
  if (field) {
    let suffix = 1; while (draft.fields.some(item => item.name === `field_${suffix}`)) suffix++;
    draft.fields.push({uuid, type, name: `field_${suffix}`, config: {label: type}, persist: type !== 'password', index: false});
  }
  return uuid;
}
