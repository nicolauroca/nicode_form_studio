const hooks = {fields:['read', 'normalize', 'validate', 'update'], operators:['evaluate'], effects:['apply'], validators:['validate']};
const modules = new Map();
const styles = new WeakMap();
export const coreBrowserProviders = {
  fields:'text textarea email telephone url search password hidden color calculated system integer decimal number currency range date time datetime-local month week select multiselect radio checkbox-group button-group checkbox toggle yes-no consent file multiple-files'.split(' '),
  operators:'equals not_equals contains not_contains starts_with ends_with in not_in empty not_empty selected not_selected greater less greater_equal less_equal between before after pattern'.split(' '),
  effects:'show hide enable disable required optional set_value clear_value change_default change_options filter_options'.split(' '),
  validators:'equals not_equals greater less greater_equal less_equal before after at_least_one exactly_n range confirmation'.split(' '),
};
for (const ids of Object.values(coreBrowserProviders)) Object.freeze(ids);
Object.freeze(coreBrowserProviders);

function bounded(promise) {
  let timer;
  return Promise.race([promise, new Promise((resolve, reject) => { timer = setTimeout(() => reject(new TypeError('Browser asset loading timed out.')), 15000); })]).finally(() => clearTimeout(timer));
}

/** Styles are scoped to the owning document, including Administrator preview frames. */
export async function loadBrowserStyles(manifest, document) {
  let loaded = styles.get(document);
  if (!loaded) { loaded = new Map(); styles.set(document, loaded); }
  const urls = new Set();
  for (const entries of Object.values(manifest || {})) for (const descriptor of Object.values(entries)) {
    if (descriptor.styles !== undefined && (!Array.isArray(descriptor.styles) || descriptor.styles.some(url => typeof url !== 'string' || !url))) throw new TypeError('Invalid browser styles.');
    for (const url of descriptor.styles || []) urls.add(url);
  }
  await Promise.all([...urls].map(url => {
    if (!loaded.has(url)) {
      const link = document.createElement('link'); link.rel = 'stylesheet'; link.href = url;
      const pending = bounded(new Promise((resolve, reject) => {
        link.onload = resolve; link.onerror = () => reject(new TypeError('Required browser style unavailable.'));
        document.head.append(link);
      })).finally(() => { link.onload = null; link.onerror = null; });
      loaded.set(url, pending);
    }
    return loaded.get(url);
  }));
}

/** URLs come exclusively from installed PHP providers resolved through Joomla WAM. */
export async function loadBrowserProviders(manifest, importer = defaultImporter) {
  const result = Object.create(null);
  await Promise.all(Object.entries(manifest || {}).map(async ([kind, entries]) => {
    if (!Object.hasOwn(hooks, kind)) throw new TypeError('Unknown browser provider kind.');
    const group = Object.create(null); result[kind] = group;
    await Promise.all(Object.entries(entries).map(async ([id, descriptor]) => {
      if (coreBrowserProviders[kind].includes(id)) throw new TypeError('Core browser providers cannot be replaced.');
      if (!/^[a-z][a-z0-9_.-]*$/.test(id) || typeof descriptor.module !== 'string' || !descriptor.module || typeof descriptor.version !== 'string') throw new TypeError('Invalid browser provider descriptor.');
      // The ES loader already deduplicates network imports. Keep the promise here
      // too, so simultaneous instances share successful and failed resolution.
      const key = importer === defaultImporter ? descriptor.module : null;
      const pending = key && modules.get(key) || bounded(Promise.resolve().then(() => importer(descriptor.module)));
      if (key) modules.set(key, pending);
      const module = await pending, provider = module.providers?.[kind]?.[id];
      if (!provider || provider.version !== descriptor.version || hooks[kind].some(hook => typeof provider[hook] !== 'function')) throw new TypeError('Required browser provider unavailable or incompatible.');
      group[id] = Object.freeze(Object.fromEntries(['version', ...hooks[kind]].map(key => [key, provider[key]])));
    }));
    Object.freeze(group);
  }));
  return Object.freeze(result);
}
const defaultImporter = url => import(url);

export function browserCall(provider, hook, ...args) {
  const value = provider[hook](...args.map(argument => structuredClone(argument)));
  if (value?.then) throw new TypeError('Browser data hooks must be synchronous.');
  return value;
}
