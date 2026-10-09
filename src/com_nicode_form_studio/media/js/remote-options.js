/** Per-instance server options cache. Stale responses never overwrite a newer input. */
export function remoteOptionInputs(spec) {
  const inputs = new Set();
  for (const field of spec.fields) {
    for (const dependency of field.source?.dependencies || []) inputs.add(dependency);
    if (field.prefill?.type === 'field') inputs.add(field.prefill.field);
  }
  const conditions = spec.rules.map(rule => rule.when);
  while (conditions.length) {
    const condition = conditions.pop();
    if (condition.group) conditions.push(...condition.children);
    else inputs.add(condition.field);
  }
  return [...inputs].sort();
}

export class RemoteOptions {
  constructor(form, fields, changed, message, previewOptions = null, inputFields = null) {
    this.inputFields = inputFields;
    this.previewOptions = previewOptions;
    this.form = form; this.fields = fields.filter(field => field.source?.type === 'remote');
    this.options = new Map(this.fields.map(field => [field.uuid, field.options || []]));
    this.changed = changed; this.message = message; this.serial = 0; this.pending = false; this.failed = false;
    if (!this.fields.length) return;
    this.status = form.ownerDocument.createElement('p'); this.status.setAttribute('role', 'status'); this.status.setAttribute('aria-live', 'polite');
    this.retry = form.ownerDocument.createElement('button'); this.retry.type = 'button'; this.retry.hidden = true; this.retry.textContent = message('options_retry', 'Retry loading options');
    this.retry.addEventListener('click', () => { this.signature = undefined; this.sync(this.values, true); this.changed(); });
    form.querySelector('.nfs-navigation').before(this.status, this.retry);
  }
  resolve(field) { return this.options.get(field.uuid) || []; }
  dispose() {
    ++this.serial; clearTimeout(this.timer); this.controller?.abort();
    this.status?.remove(); this.retry?.remove(); this.pending = false;
  }
  sync(values, force = false) {
    if (!this.fields.length) return;
    this.values = values;
    const signature = JSON.stringify(this.inputFields === null ? values : [
      this.inputFields.map(uuid => values[uuid] ?? null),
      this.fields.map(field => Object.hasOwn(values, field.uuid)),
    ]);
    if (this.signature === undefined && !force) { this.signature = signature; return; }
    if (!force && signature === this.signature) return;
    this.signature = signature; const serial = ++this.serial;
    clearTimeout(this.timer); this.controller?.abort(); this.retry.hidden = true;
    if (this.form.dataset.nfsPreview === 'true' && !this.previewOptions) {
      this.failed = true; this.status.textContent = this.message('options_preview', 'Dynamic options update in the published form.'); return;
    }
    this.pending = true; this.failed = false; this.status.textContent = this.message('options_loading', 'Loading options…');
    this.timer = setTimeout(() => this.load(serial), 250);
  }
  async load(serial) {
    if (!this.form.isConnected || serial !== this.serial) return;
    this.controller = new AbortController(); const controller = this.controller;
    const timeout = setTimeout(() => controller.abort(), 15000);
    try {
      let result;
      if (this.form.dataset.nfsPreview === 'true') {
        if (!this.previewOptions) throw new Error('options_unavailable');
        result = {ok:true, ...await this.previewOptions(this.values, controller.signal)};
      } else {
      const url = new URL(this.form.action, this.form.ownerDocument.baseURI); url.searchParams.set('task', 'form.options'); url.searchParams.set('format', 'json');
      const body = new URLSearchParams();
      for (const [key, value] of new FormData(this.form)) if (typeof value === 'string') body.append(key, value);
      const response = await fetch(url, {method: 'POST', body, credentials: 'same-origin', cache: 'no-store', signal: controller.signal, headers: {Accept: 'application/json'}});
      result = await response.json();
      if (!response.ok) throw new Error('options_unavailable');
      }
      if (serial !== this.serial) return;
      if (result?.ok !== true || !result.options || typeof result.options !== 'object' || Array.isArray(result.options)) throw new Error('options_unavailable');
      const next = new Map();
      for (const field of this.fields) {
        const options = result.options[field.uuid];
        if (!Array.isArray(options) || options.some(option => !option || Array.isArray(option) || typeof option.value !== 'string' || typeof option.label !== 'string' || typeof option.enabled !== 'boolean'
          || (Object.hasOwn(option, 'default') && typeof option.default !== 'boolean'))
          || new Set(options.map(option => option.value)).size !== options.length) throw new Error('options_unavailable');
        next.set(field.uuid, options);
      }
      this.options = next; this.status.textContent = ''; this.failed = false;
    } catch {
      if (serial !== this.serial) return;
      this.failed = true; this.status.textContent = this.message('options_unavailable', 'Options could not be loaded. Retry before submitting.'); this.retry.hidden = false;
    } finally {
      clearTimeout(timeout);
      if (serial === this.serial) { this.pending = false; this.changed(); }
    }
  }
}
