import test from 'node:test';
import assert from 'node:assert/strict';
import {FormInstance} from '../../src/com_nicode_form_studio/media/js/form-instance.js';

test('error navigation opens the owning step and skips disabled or hidden controls', () => {
  const events = [], node = {focus:()=>events.push('node')};
  const controls = [{disabled:true,focus:()=>assert.fail('Disabled control focused')},{type:'hidden',focus:()=>assert.fail('Hidden control focused')},{type:'radio',focus:()=>events.push('input')}];
  const instance = {controls:new Map([['field',controls]]),nodes:new Map([['field',node]]),steps:[{contains:()=>false},{contains:()=>true}],currentStep:0,updateSteps:()=>events.push('step')};
  FormInstance.prototype.focusErrorField.call(instance,'field');
  assert.equal(instance.currentStep,1); assert.deepEqual(events,['step','input']);
  events.length = 0; instance.controls.set('field',[]);
  FormInstance.prototype.focusErrorField.call(instance,'field'); assert.deepEqual(events,['step','node']);
  events.length = 0; FormInstance.prototype.focusErrorField.call(instance,'missing'); assert.deepEqual(events,[]);
});
