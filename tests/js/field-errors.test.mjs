import test from 'node:test';
import assert from 'node:assert/strict';
import {updateFieldError, activeFieldErrors} from '../../src/com_nicode_form_studio/media/js/field-errors.js';

test('rule deactivation removes only inactive field errors without mutating the original errors', () => {
  const errors = {hidden:['Required'],visible:['Invalid'],server:['Other']};
  const filtered = activeFieldErrors(errors,{hidden:{active:false},visible:{active:true}});
  assert.deepEqual(filtered,{visible:['Invalid'],server:['Other']});
  assert.deepEqual(errors.hidden,['Required']);
  assert.deepEqual(activeFieldErrors(filtered,{hidden:{active:true},visible:{active:true}}),filtered);
});

function element() {
  const attributes = new Map();
  return {id:'', textContent:'', hidden:false,
    getAttribute:key => attributes.get(key) ?? null,
    setAttribute:(key,value) => attributes.set(key,value),
    removeAttribute:key => attributes.delete(key),
  };
}
function fixture() {
  let inline = null;
  return {querySelector:() => inline, ownerDocument:{createElement:element}, append:value => { inline = value; }};
}

test('inline errors share one association for a choice group and preserve provider descriptions', () => {
  const node = fixture(), controls = [element(),element()];
  for (const control of controls) control.setAttribute('aria-describedby','help provider-help');
  updateFieldError('instance-a','choice',node,controls,['Choose one.','<script>literal</script>']);
  const error = node.querySelector();
  assert.equal(error.textContent,'Choose one. <script>literal</script>');
  for (const control of controls) {
    assert.equal(control.getAttribute('aria-describedby'),'help provider-help instance-a-choice-error');
    assert.equal(control.getAttribute('aria-invalid'),'true');
  }
  updateFieldError('instance-a','choice',node,controls,['Updated']);
  assert.equal(node.querySelector(),error); assert.equal(error.textContent,'Updated');
  updateFieldError('instance-a','choice',node,controls,undefined);
  assert.equal(error.hidden,true); assert.equal(error.textContent,'');
  for (const control of controls) {
    assert.equal(control.getAttribute('aria-describedby'),'help provider-help');
    assert.equal(control.getAttribute('aria-invalid'),null);
  }
});

test('feedback IDs remain isolated across instances and empty feedback creates no node', () => {
  const a = fixture(), b = fixture(), control = element();
  updateFieldError('a','field',a,[control],[]); assert.equal(a.querySelector(),null);
  updateFieldError('a','field',a,[control],['Required']);
  updateFieldError('b','field',b,[element()],['Required']);
  assert.notEqual(a.querySelector().id,b.querySelector().id);
  updateFieldError('a','field',a,[control],[]); assert.equal(control.getAttribute('aria-describedby'),null);
});
