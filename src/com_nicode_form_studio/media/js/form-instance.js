import { evaluateRules } from './rules.js';
import {updateFieldError, activeFieldErrors} from './field-errors.js';
import {recoverRuleFocus} from './focus-recovery.js';
import { normalizeField, validateField, validateRelations, nativeConstraintInvalid } from './validation.js';
import {RemoteOptions, remoteOptionInputs} from './remote-options.js';
import {packSubmissionValues} from './submission-data.js';
import {repeatedErrors} from './repeated-validation.js';
import {parseFieldAddress} from './field-address.js';
import {editRow, updateRowButtons} from './row-editor.js';
import {loadBrowserProviders, loadBrowserStyles} from './browser-providers.js';

export function createFormInstance(form, previewOptions = null, previewRows = null) {
  const manifest = JSON.parse(form.querySelector('[data-nfs-definition]').textContent).browser_providers || {};
  if (!Object.keys(manifest).length) return new FormInstance(form, {}, previewOptions, previewRows);
  return Promise.all([loadBrowserProviders(manifest), loadBrowserStyles(manifest, form.ownerDocument)]).then(([providers]) => new FormInstance(form, providers, previewOptions, previewRows));
}

export class FormInstance {
  constructor(form, providers = {}, previewOptions = null, previewRows = null) {
    this.previewOptions = previewOptions; this.previewRows = previewRows;
    this.form = form;
    this.providers = providers;
    this.spec = JSON.parse(form.querySelector('[data-nfs-definition]').textContent);
    for (const [kind, entries] of Object.entries(this.spec.browser_providers || {})) {
      for (const [id, descriptor] of Object.entries(entries)) {
        if (providers[kind]?.[id]?.version !== descriptor.version) throw new TypeError('Required browser provider has not loaded.');
      }
    }
    this.controls = new Map(this.spec.fields.map(field => [field.uuid, [...form.querySelectorAll(`[data-nfs-input="${CSS.escape(field.uuid)}"]`)]]));
    this.nodes = new Map([...form.querySelectorAll('[data-nfs-element]')].map(node => [node.dataset.nfsElement, node]));
    this.result = form.querySelector('.nfs-result');
    this.summary = form.querySelector('.nfs-validation-summary');
    this.errors = Object.fromEntries(this.spec.fields.flatMap(field => {
      const text = this.nodes.get(field.uuid)?.querySelector('[data-nfs-error]')?.textContent;
      return text ? [[field.uuid, [text]]] : [];
    }));
    this.currentStep = 0;
    for (const element of this.spec.elements.filter(element => element.type === 'repeatable-group')) {
      const text = this.nodes.get(element.uuid)?.querySelector(`[data-nfs-error="${CSS.escape(element.uuid)}"]`)?.textContent;
      if (text) this.errors[element.uuid] = [text];
    }
    this.pending = false;
    this.initialValues = new Map(this.spec.fields.map(field => [field.uuid, this.read(field)]));
    this.remoteOptions = new RemoteOptions(form, this.spec.fields, () => this.refresh(), this.message.bind(this), previewOptions, remoteOptionInputs(this.spec));
    this.form.noValidate = true;
    form.addEventListener('input', () => this.refresh());
    form.addEventListener('change', () => this.refresh());
    form.addEventListener('click', event => {
      const button = event.target.closest?.('button[type="button"][data-nfs-row-change]');
      if (this.form.dataset.nfsPreview === 'true' && button && this.form.contains(button)) {
        event.preventDefault(); editRow(this, button);
      }
    });
    form.addEventListener('submit', event => {
      if (event.submitter?.matches('[data-nfs-row-change]')) {
        event.preventDefault(); editRow(this,event.submitter); return;
      }
      event.preventDefault(); this.submit();
    });
    form.querySelector('[data-nfs-previous]').addEventListener('click', () => this.move(-1));
    form.querySelector('[data-nfs-next]').addEventListener('click', () => this.move(1));
    this.summary.addEventListener('click', event => {
      const link = event.target.closest('[data-nfs-error-target]');
      if (!link) return;
      event.preventDefault(); this.focusErrorField(link.dataset.nfsErrorTarget);
    });
    this.refresh();
  }
  message(key, fallback) { return this.spec.messages?.[key] || fallback; }
  read(field) {
    const controls = this.controls.get(field.uuid);
    if (this.providers?.fields?.[field.type]) return this.providers.fields[field.type].read([...controls], structuredClone(field));
    if (this.spec.datatypes[field.uuid] === 'boolean') return controls[0]?.checked || false;
    if (field.type === 'multiselect') return [...controls[0].selectedOptions].map(option => option.value);
    if (field.type === 'checkbox-group') return controls.filter(control => control.checked).map(control => control.value);
    if (['radio','button-group'].includes(field.type)) return controls.find(control => control.checked)?.value ?? null;
    if (['file','multiple-files'].includes(field.type)) return [...(controls[0]?.files || [])];
    if (field.type === 'calculated') return this.form.querySelector(`[data-nfs-output="${CSS.escape(field.uuid)}"]`)?.textContent ?? field.config?.default ?? null;
    return controls[0]?.value ?? null;
  }
  refresh() {
    const values = {}, normalizationErrors = {};
    const active = this.form.ownerDocument?.activeElement;
    const previousFocus = active && this.form.contains(active) ? active : null;
    const recovering = this.configurationError;
    try {
      for (const field of this.spec.fields) {
        try { values[field.uuid] = normalizeField(field, this.read(field), this.spec.datatypes[field.uuid], this.providers); }
        catch { values[field.uuid] = null; normalizationErrors[field.uuid] = ['type']; }
      }
      this.state = evaluateRules(this.spec, values, this.spec.datatypes, 64, field => this.remoteOptions.resolve(field), this.providers);
      let selectionChanged = false;
      this.normalizationErrors = normalizationErrors;
      for (const [uuid, state] of Object.entries(this.state.states)) {
        const node = this.nodes.get(uuid);
        if (node) node.hidden = !state.active;
      }
      for (const field of this.spec.fields) {
        const state = this.state.states[field.uuid]; let controls = this.controls.get(field.uuid);
        const locked = Boolean(field.config?.readonly) && (['selection', 'boolean', 'file'].includes(this.spec.datatypes[field.uuid]) || ['range', 'color'].includes(field.type));
        for (const control of controls) {
          control.disabled = !state.active || locked;
          control.required = state.active && !locked && state.required && field.type !== 'checkbox-group';
        }
        if (this.providers?.fields?.[field.type]) {
          const updated = this.providers.fields[field.type].update([...controls], structuredClone(field), structuredClone(state));
          if (updated?.then) throw new TypeError('Widget update must be synchronous.');
          continue;
        }
        if (['select','multiselect'].includes(field.type)) {
          const select = controls[0], previous = this.read(field);
          const signature = JSON.stringify(state.options);
          if (select.dataset.nfsOptions !== signature) {
            const options = state.options.filter(option => option.enabled ?? true);
            select.replaceChildren();
            if (field.type === 'select') select.add(new Option('', ''));
            const initial = this.initialValues.get(field.uuid);
            for (const option of options) select.add(new Option(option.label, option.value, Array.isArray(initial) ? initial.includes(option.value) : initial === option.value, Array.isArray(previous) ? previous.includes(option.value) : previous === option.value));
            select.dataset.nfsOptions = signature;
            if (JSON.stringify(previous) !== JSON.stringify(this.read(field))) selectionChanged = true;
          }
        }
        if (['radio', 'checkbox-group', 'button-group'].includes(field.type)) {
          const choices = this.nodes.get(field.uuid)?.querySelector('[data-nfs-choices]');
          const signature = JSON.stringify(state.options), previous = this.read(field);
          if (choices && choices.dataset.nfsOptions !== signature) {
            const initial = this.initialValues.get(field.uuid), multiple = field.type === 'checkbox-group';
            const describedBy = controls[0]?.getAttribute('aria-describedby') || `${this.form.id}-${field.uuid}-help`;
            const invalid = controls.some(control => control.getAttribute('aria-invalid') === 'true') || choices.dataset.nfsInvalid === 'true';
            const focused = controls.find(control => control === this.form.ownerDocument.activeElement)?.value;
            choices.dataset.nfsInvalid = String(invalid); choices.replaceChildren(); controls = [];
            for (const [index, option] of state.options.filter(option => option.enabled ?? true).entries()) {
              const wrapper = this.form.ownerDocument.createElement('div'), input = this.form.ownerDocument.createElement('input'), label = this.form.ownerDocument.createElement('label');
              input.type = multiple ? 'checkbox' : 'radio'; input.name = `nfs[${field.uuid}]${multiple ? '[]' : ''}`;
              input.id = `${this.form.id}-${field.uuid}-${index}`; input.dataset.nfsInput = field.uuid; input.value = option.value;
              input.defaultChecked = multiple ? (initial || []).includes(option.value) : initial === option.value;
              input.checked = multiple ? (previous || []).includes(option.value) : previous === option.value;
              input.disabled = !state.active || locked; input.required = state.active && !locked && state.required && !multiple;
              if (describedBy) input.setAttribute('aria-describedby', describedBy);
              if (invalid) input.setAttribute('aria-invalid', 'true');
              label.htmlFor = input.id; label.textContent = option.label; wrapper.append(input, label); choices.append(wrapper); controls.push(input);
            }
            this.controls.set(field.uuid, controls); choices.dataset.nfsOptions = signature;
            if (focused !== undefined) (controls.find(control => control.value === focused) || controls[0])?.focus();
            if (JSON.stringify(previous) !== JSON.stringify(this.read(field))) selectionChanged = true;
          }
        }
        if (state.active && state.value !== values[field.uuid] && !['file','multiple-files'].includes(field.type)) {
          for (const control of controls) {
            if (control.type === 'checkbox' || control.type === 'radio') control.checked = Array.isArray(state.value) ? state.value.includes(control.value) : this.spec.datatypes[field.uuid] === 'boolean' ? state.value === true : state.value === control.value;
            else if (control.multiple) for (const option of control.options) option.selected = (state.value || []).includes(option.value);
            else control.value = state.value ?? '';
          }
        }
        const output = this.form.querySelector(`[data-nfs-output="${CSS.escape(field.uuid)}"]`);
        if (output) output.textContent = state.value ?? '';
      }
      const previousStep = this.steps?.[this.currentStep];
      const allSteps = [...this.form.querySelectorAll('[data-nfs-step]')];
      this.steps = allSteps.filter(step => this.state.states[step.dataset.nfsStep]?.active);
      const retainedIndex = this.steps.indexOf(previousStep);
      const followingIndex = previousStep ? this.steps.findIndex(step => allSteps.indexOf(step) > allSteps.indexOf(previousStep)) : 0;
      this.currentStep = retainedIndex >= 0 ? retainedIndex : followingIndex >= 0 ? followingIndex : Math.max(0, this.steps.length - 1);
      this.updateSteps();
      if (Object.keys(this.errors || {}).some(uuid => this.state.states[uuid]?.active === false)) this.showErrors(activeFieldErrors(this.errors, this.state.states), false);
      this.configurationError = false;
      updateRowButtons(this);
      if (recovering) this.result.textContent = '';
      this.form.querySelector('[data-nfs-previous]').disabled = false;
      this.form.querySelector('[data-nfs-next]').disabled = false;
      this.remoteOptions.sync(this.state.values);
      this.form.querySelector('[data-nfs-submit]').disabled = this.pending || this.remoteOptions.pending || this.remoteOptions.failed || this.form.dataset.nfsPreview === 'true';
      recoverRuleFocus(this.form, previousFocus);
      if (selectionChanged) queueMicrotask(() => this.refresh());
    } catch {
      this.configurationError = true;
      this.result.textContent = this.message('form_unavailable', 'This form is temporarily unavailable.');
      for (const button of this.form.querySelectorAll('[data-nfs-submit], [data-nfs-next], [data-nfs-previous], [data-nfs-row-change]')) button.disabled = true;
    }
  }
  updateSteps() {
    this.steps.forEach((step, index) => { step.hidden = index !== this.currentStep; step.setAttribute('aria-current', index === this.currentStep ? 'step' : 'false'); });
    this.form.querySelector('[data-nfs-previous]').hidden = this.currentStep === 0;
    this.form.querySelector('[data-nfs-next]').hidden = this.steps.length < 2 || this.currentStep >= this.steps.length - 1;
    this.form.querySelector('[data-nfs-submit]').hidden = this.steps.length > 1 && this.currentStep < this.steps.length - 1;
    this.form.querySelector('[data-nfs-progress]').textContent = this.steps.length > 1 ? `${this.currentStep + 1} / ${this.steps.length}` : '';
  }
  validate(step = null) {
    try { return this.validateValues(step); }
    catch {
      this.configurationError = true;
      this.result.textContent = this.message('form_unavailable', 'This form is temporarily unavailable.');
      for (const button of this.form.querySelectorAll('[data-nfs-submit], [data-nfs-next], [data-nfs-previous], [data-nfs-row-change]')) button.disabled = true;
      return false;
    }
  }
  validateValues(step = null) {
    this.refresh();
    if (this.configurationError || !this.state || this.remoteOptions.pending || this.remoteOptions.failed) return false;
    const errors = {};
    for (const [uuid, codes] of Object.entries(repeatedErrors(this.spec, this.state.states))) {
      if (!step || step.contains(this.nodes.get(uuid))) errors[uuid] = codes.map(code => this.message(code, 'Add the required number of entries.'));
    }
    for (const field of this.spec.fields) {
      if (step && !step.contains(this.nodes.get(field.uuid))) continue;
      const state = this.state.states[field.uuid];
      if (!state.active) continue;
      const problems = this.normalizationErrors[field.uuid] || this.state.normalizationErrors?.[field.uuid] || validateField(field, state.value, this.spec.datatypes[field.uuid], state, this.providers);
      const invalid = this.controls.get(field.uuid).find(control => nativeConstraintInvalid(field, state.value, control, this.providers));
      if (problems.length || invalid) errors[field.uuid] = [problems.length ? (field.config.validation_messages?.[problems[0]] ?? this.message(problems[0], 'Please check this value.')) : invalid.validationMessage || this.message('type', 'Please check this value.')];
    }
    const accepted = Object.fromEntries(Object.entries(this.state.values).filter(([uuid]) => !errors[uuid]));
    const deferredFields = new Set();
    if (step) {
      const followingSteps = this.steps.slice(this.steps.indexOf(step) + 1);
      for (const field of this.spec.fields) {
        if (followingSteps.some(following => following.contains(this.nodes.get(field.uuid)))) deferredFields.add(field.uuid);
      }
    }
    for (const [uuid, codes] of Object.entries(validateRelations(this.spec, accepted, this.spec.datatypes, this.state.states, this.providers, deferredFields))) {
      const custom = this.spec.fields.find(field => field.uuid === uuid)?.config.validation_messages;
      if (!step || step.contains(this.nodes.get(uuid))) (errors[uuid] ||= []).push(...codes.map(code => custom?.[code] ?? this.message(code, 'Please check related fields.')));
    }
    this.showErrors(errors);
    return Object.keys(errors).length === 0 && !this.configurationError;
  }
  showErrors(errors, focus = true) {
    this.errors = errors;
    this.summary.replaceChildren();
    this.summary.hidden = Object.keys(errors).length === 0;
    for (const [uuid, controls] of this.controls) updateFieldError(this.form.id, uuid, this.nodes.get(uuid), controls, errors[uuid]);
    for (const element of this.spec.elements.filter(element => element.type === 'repeatable-group')) {
      const node = this.nodes.get(element.uuid);
      updateFieldError(this.form.id, element.uuid, node, node ? [node] : [], errors[element.uuid]);
    }
    for (const [uuid, node] of this.nodes) {
      const choices = node.querySelector('[data-nfs-choices]');
      if (choices) choices.dataset.nfsInvalid = String(Object.hasOwn(errors, uuid));
    }
    if (this.summary.hidden) return;
    const title = document.createElement('p'); title.textContent = this.message('validation_error', 'Please review the highlighted fields.'); this.summary.append(title);
    const list = document.createElement('ul'); this.summary.append(list);
    for (const [uuid, messages] of Object.entries(errors)) {
      const controls = this.controls.get(uuid) || [], first = controls[0];
      for (const control of controls) control.setAttribute('aria-invalid', 'true');
      const item = document.createElement('li'), link = document.createElement('a');
      const label = this.spec.fields.find(field => field.uuid === uuid)?.config?.label ?? this.spec.elements.find(element => element.uuid === uuid)?.title;
      link.textContent = (label ? `${label}: ` : '') + (Array.isArray(messages) ? messages.join(' ') : String(messages));
      const target = first || this.nodes.get(uuid);
      if (target) {
        link.href = `#${target.id}`;
        link.addEventListener('click', event => {
          event.preventDefault(); this.focusErrorField(uuid);
        });
      }
      item.append(link); list.append(item);
    }
    if (focus) this.summary.focus();
  }
  focusErrorField(uuid) {
    const target = this.controls.get(uuid)?.find(control => !control.disabled && control.type !== 'hidden') || this.nodes.get(uuid);
    if (!target) return;
    const index = this.steps.findIndex(step => step.contains(target));
    if (index >= 0) { this.currentStep = index; this.updateSteps(); }
    target.focus();
  }
  move(delta) {
    if (delta > 0 && !this.validate(this.steps[this.currentStep])) return;
    this.currentStep = Math.max(0, Math.min(this.steps.length - 1, this.currentStep + delta)); this.updateSteps();
    const step = this.steps[this.currentStep]; if (step) { step.tabIndex = -1; step.focus(); }
  }
  async submit() {
    if (this.pending || this.form.dataset.nfsPreview === 'true' || !this.validate()) return;
    this.pending = true;
    if (this.spec?.instances) updateRowButtons(this);
    const button = this.form.querySelector('[data-nfs-submit]'); button.disabled = true;
    this.form.setAttribute('aria-busy', 'true'); this.result.textContent = this.message('submitting', 'Submitting…');
    const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 30000);
    try {
      const data = packSubmissionValues(new FormData(this.form)); data.set('format', 'json');
      const response = await fetch(this.form.action, {method:'POST', body:data, credentials:'same-origin', signal:controller.signal, headers:{Accept:'application/json'}});
      const result = await response.json();
      if (!result || typeof result !== 'object' || Array.isArray(result)) throw new Error('invalid_response');
      if (result.errors && Object.keys(result.errors).length) { this.showErrors(result.errors); this.result.textContent = result.message || this.message('validation_error','Please check the form.'); return; }
      if (!response.ok || result.accepted !== true) {
        this.result.textContent = typeof result.message === 'string' && result.message.trim()
          ? result.message : this.message('unexpected_error', 'The response could not be confirmed. Please retry.');
        this.result.focus();
        return;
      }
      this.result.textContent = result.message || this.message('success', 'Your response has been received.');
      if (result.reference) this.result.textContent += ` ${result.reference}`;
      if (typeof result.heading === 'string' && result.heading.trim()) {
        const heading = document.createElement('h2'); heading.textContent = result.heading; this.result.prepend(heading);
      }
      if (Array.isArray(result.summary) && result.summary.length) {
        const summary = document.createElement('dl'); summary.className = 'nfs-confirmation-summary';
        for (const item of result.summary) {
          const label = document.createElement('dt'), value = document.createElement('dd');
          label.textContent = item.label; value.textContent = item.value; summary.append(label, value);
        }
        this.result.append(summary);
      }
      if (result.behavior === 'reset') {
        const preserve = new Set(result.preserve || []), saved = new Map(this.spec.fields.filter(field => preserve.has(parseFieldAddress(field.uuid).field)).map(field => [field.uuid, this.read(field)]));
        this.form.reset();
        for (const [uuid, value] of saved) for (const control of this.controls.get(uuid)) {
          if (control.type === 'checkbox' || control.type === 'radio') control.checked = Array.isArray(value) ? value.includes(control.value) : typeof value === 'boolean' ? value : value === control.value;
          else if (control.multiple) for (const option of control.options) option.selected = value.includes(option.value);
          else if (control.type !== 'file') control.value = value ?? '';
        }
        this.currentStep = 0; this.steps = []; this.refresh();
      }
      if (result.behavior === 'hide') { this.form.querySelector('.nfs-elements').hidden = true; this.form.querySelector('.nfs-navigation').hidden = true; }
      if (result.next_attempt) this.form.elements.namedItem('attempt').value = result.next_attempt;
      if (result.redirect) {
        const destination = new URL(result.redirect, location.href);
        // Server RedirectPolicy validates the configured destination before responding.
        if (destination.protocol === 'https:' || (destination.origin === location.origin && destination.protocol === 'http:')) location.assign(destination.href);
      }
      this.result.focus();
    } catch (error) {
      this.result.textContent = error.name === 'AbortError'
        ? this.message('timeout', 'The response is delayed. Retrying will use the same submission reference.')
        : this.message('unexpected_error', 'The response could not be confirmed. Please retry.');
      this.result.focus();
    } finally {
      clearTimeout(timeout); this.pending = false; button.disabled = this.remoteOptions.pending || this.remoteOptions.failed; this.form.removeAttribute('aria-busy');
      if (this.spec?.instances) updateRowButtons(this);
    }
  }
}
