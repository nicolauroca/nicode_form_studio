import test from 'node:test';
import assert from 'node:assert/strict';
import {mountRepeatLimits} from '../../src/com_nicode_form_studio/media/js/repeat-limits-editor.js';

function mount(element) {
  const controls = {}, messages = [];
  mountRepeatLimits({append:message=>messages.push(message)}, element, {
    t:key=>key, node:(_tag,text)=>text,
    control(_host,_label,object,key,schema,options) {
      const input = {min:schema.minimum,max:schema.maximum, edit(value) { object[key]=value; options.onChange(); }};
      controls[key]=input; return input;
    },
  });
  return {controls,messages};
}

test('repeat limits remain typed and coordinate required bounds without allocating rows', () => {
  const element = {type:'repeatable-group',repeat:{min:1,max:3}};
  const {controls} = mount(element);
  assert.equal(controls.min.required,true); assert.equal(controls.max.required,true);
  assert.equal(controls.min.max,3); assert.equal(controls.max.min,1);
  controls.min.edit(4);
  assert.deepEqual(element.repeat,{min:4,max:3}); assert.equal(controls.max.min,4);
  controls.max.edit(6); assert.equal(controls.min.max,6);
  controls.min.edit(0); controls.max.edit(0);
  assert.deepEqual(element.repeat,{min:0,max:0});
});

test('repeat inspector preserves invalid imported values and does not alter ordinary groups', () => {
  const ordinary={type:'group'}; assert.deepEqual(mount(ordinary).controls,{}); assert.equal(Object.hasOwn(ordinary,'repeat'),false);
  const element={type:'repeatable-group',repeat:{min:'wrong',max:-1}};
  const {controls}=mount(element);
  assert.deepEqual(element.repeat,{min:'wrong',max:-1});
  assert.equal(controls.max.min,0); assert.equal(controls.min.max,10000);
  const absent={type:'repeatable-group'}; mount(absent); assert.deepEqual(absent.repeat,{});
});
