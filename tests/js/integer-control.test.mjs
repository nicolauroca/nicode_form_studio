import test from 'node:test';
import assert from 'node:assert/strict';
import {bindIntegerControl} from '../../src/com_nicode_form_studio/media/js/integer-control.js';

function control(object, key, required = false, tagName = 'INPUT') {
  const handlers = {};
  const input = {tagName, value:String(object[key] ?? ''), validity:{badInput:false}, custom:'', reports:0, changes:0,
    setCustomValidity(message) { this.custom = message; },
    addEventListener(type, callback) { handlers[type] = callback; },
    checkValidity() { return !this.custom && !this.validity.badInput && (this.value === '' || Number(this.value) >= 0); },
    reportValidity() { this.reports++; },
    edit(value, badInput = false) { this.value = value; this.validity.badInput = badInput; handlers[tagName === 'SELECT' ? 'change' : 'input'](); },
  };
  bindIntegerControl(input, object, key, {required, message:'Invalid integer', changed:() => input.changes++});
  return input;
}

test('invalid numeric edits survive inspector reconstruction instead of restoring old values', () => {
  const draft = {minimum:5}; let input = control(draft, 'minimum');
  input.edit('-1'); assert.equal(draft.minimum, -1); assert.equal(input.changes, 1); assert.equal(input.reports, 1);
  input = control(draft, 'minimum'); assert.equal(input.value, '-1'); assert.equal(input.checkValidity(), false);
  for (const raw of ['1.5', '9007199254740993', '1e309']) {
    input.edit(raw); assert.equal(draft.minimum, raw);
    input = control(draft, 'minimum'); assert.equal(input.checkValidity(), false);
  }
  input.edit('7'); assert.equal(draft.minimum, 7); assert.equal(input.checkValidity(), true);
});

test('incomplete numeric syntax remains invalid across reconstruction but explicit clear removes optional property', () => {
  const draft = {minimum:2}; let input = control(draft, 'minimum');
  input.edit('', true); assert.equal(draft.minimum, '');
  input = control(draft, 'minimum'); assert.equal(input.checkValidity(), false);
  input.edit(''); assert.equal(Object.hasOwn(draft, 'minimum'), false); assert.equal(input.checkValidity(), true);
  input = control(draft, 'minimum', true); input.edit(''); assert.equal(input.checkValidity(), false); assert.equal(draft.minimum, '');
});

test('integer select stores numbers and removes a selected default independently', () => {
  const widths = {mobile:12, tablet:6}; const input = control(widths, 'mobile', false, 'SELECT');
  input.edit('4'); assert.deepEqual(widths, {mobile:4, tablet:6});
  input.edit(''); assert.deepEqual(widths, {tablet:6});
});
