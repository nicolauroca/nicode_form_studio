import test from 'node:test';
import assert from 'node:assert/strict';
import {correlationControl} from '../../src/com_nicode_form_studio/media/js/search-correlation.js';

test('saved row correlation survives editing and incompatible fields require explicit removal', () => {
  const input = {addEventListener(_event, listener) { this.edit = listener; }, setCustomValidity(message) { this.error = message; }};
  const control = correlationControl(input, 'contact_2', 'unsupported');
  assert.equal(control.update(true), true); assert.equal(input.error, '');
  assert.deepEqual(control.apply({field:'email'}), {field:'email',same_instance:'contact_2'});
  assert.equal(control.update(false), true); assert.equal(input.error, 'unsupported');
  assert.equal(control.apply({}).same_instance, 'contact_2');
  input.value = ''; input.edit(); assert.equal(input.error, ''); assert.deepEqual(control.apply({}), {});
  assert.equal(control.update(false), false);
  control.update(true); input.value = 'new_group'; input.edit(); assert.equal(control.apply({}).same_instance,'new_group');
  assert.equal(input.maxLength,32); assert.equal(new RegExp(`^(?:${input.pattern})$`).test('1 invalid'),false);
});
