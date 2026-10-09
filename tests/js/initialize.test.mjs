import test from 'node:test';
import assert from 'node:assert/strict';
import {createInitializer} from '../../src/com_nicode_form_studio/media/js/initialize.js';
import {FormInstance} from '../../src/com_nicode_form_studio/media/js/form-instance.js';

function form(broken = false) {
  const buttons = [{disabled:false}, {disabled:false}, {disabled:false}];
  const result = {textContent:'', hidden:true, setAttribute(name, value) { this[name] = value; }};
  const listeners = [];
  return {broken, buttons, result, listeners, dataset:{nfsUnavailable:'No disponible <seguro>'}, matches: () => true,
    querySelector: () => result, querySelectorAll: selector => selector === '[data-nfs-form]' ? [] : buttons,
    addEventListener: (...args) => listeners.push(args)};
}

test('failed form initialization preserves subsequent instances and blocks only its own submission', () => {
  const first = form(true), second = form(), initialized = [];
  const initialize = createInitializer(candidate => {
    if (candidate.broken) throw new Error('private provider exception');
    initialized.push(candidate); return {form:candidate};
  });
  const root = {querySelectorAll: () => [first, second]};
  initialize(root); initialize(root);
  assert.deepEqual(initialized, [second]);
  assert.equal(first.dataset.nfsRuntime, 'unavailable');
  assert.equal(second.dataset.nfsRuntime, 'initialized');
  assert.equal(first.result.textContent, 'No disponible <seguro>');
  assert.equal(first.result.role, 'alert'); assert.equal(first.result.hidden, false);
  assert.ok(first.buttons.every(button => button.disabled));
  assert.ok(second.buttons.every(button => !button.disabled));
  assert.equal(first.listeners.length, 1); assert.deepEqual(first.listeners[0][2], {capture:true});
  const intercepted = [];
  first.listeners[0][1]({preventDefault: () => intercepted.push('prevent'), stopImmediatePropagation: () => intercepted.push('stop')});
  assert.deepEqual(intercepted, ['prevent', 'stop']);
});

test('Joomla updates can initialize the form itself without duplicate listeners', () => {
  const candidate = form(); let count = 0;
  const initialize = createInitializer(() => { count++; return {}; });
  initialize(candidate); initialize(candidate);
  assert.equal(count, 1); assert.equal(candidate.dataset.nfsRuntime, 'initialized');
});

test('asynchronous provider failures block only dependent forms while pending imports prevent submission', async () => {
  const failed = form(), healthy = form(); let reject;
  failed.removeEventListener = () => {};
  const initialize = createInitializer(candidate => candidate === failed ? new Promise((resolve, fail) => { reject = fail; }) : {form:candidate});
  initialize({querySelectorAll:() => [failed, healthy]});
  assert.equal(failed.dataset.nfsRuntime, 'loading');
  assert.ok(failed.buttons.every(button => button.disabled));
  assert.equal(healthy.dataset.nfsRuntime, 'initialized');
  reject(new Error('missing provider asset'));
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(failed.dataset.nfsRuntime, 'unavailable');
  assert.ok(healthy.buttons.every(button => !button.disabled));
});

test('a runtime rule failure disables navigation and a valid reevaluation recovers it', () => {
  const instance = Object.create(FormInstance.prototype);
  const controls = Object.fromEntries(['previous', 'next', 'submit', 'progress'].map(key => [key, {hidden:false, disabled:false, textContent:''}]));
  instance.spec = {fields:[], elements:[], rules:[{uuid:'broken', when:{field:'unknown', operator:'equals'}, effects:[]}], datatypes:{}, messages:{form_unavailable:'Temporarily unavailable'}};
  instance.form = {dataset:{}, querySelector: selector => controls[selector.slice(10, -1)], querySelectorAll: selector => selector.includes(',') ? Object.values(controls).slice(0, 3) : []};
  instance.nodes = new Map(); instance.controls = new Map(); instance.result = {textContent:''}; instance.currentStep = 0;
  instance.remoteOptions = {sync() {}, resolve() {}, pending:false, failed:false}; instance.pending = false;
  instance.refresh();
  assert.equal(instance.configurationError, true); assert.equal(controls.submit.disabled, true);
  assert.equal(controls.next.disabled, true); assert.equal(instance.result.textContent, 'Temporarily unavailable');
  instance.spec.rules = []; instance.refresh();
  assert.equal(instance.configurationError, false); assert.equal(controls.submit.disabled, false);
  assert.equal(controls.next.disabled, false); assert.equal(instance.result.textContent, '');
});
