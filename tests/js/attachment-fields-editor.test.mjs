import test from 'node:test';
import assert from 'node:assert/strict';
import {mountAttachmentFields} from '../../src/com_nicode_form_studio/media/js/attachment-fields-editor.js';
function setup(configuration, fields) {
  const inputs=[]; let changes=0;
  const node=(tag,text,attributes={})=>({tag,text,attributes,children:[],append(...items){this.children.push(...items);},replaceWith(){},addEventListener(type,fn){this[type]=fn;}});
  const host=node('div');
  mountAttachmentFields(host,configuration,fields,{node:(...args)=>{const item=node(...args);if(item.tag==='input')inputs.push(item);return item;},button:(text,click)=>({text,click}),t:key=>key,changed:()=>changes++});
  return {host,inputs,changes:()=>changes};
}
test('attachment selector excludes private files and preserves explicit selection order',()=>{
  const fields=[{uuid:'text',type:'text'},{uuid:'private',type:'file',sensitive:true},{uuid:'a',type:'file'},{uuid:'b',type:'multiple-files',sensitive:true,include_email:true}];
  const config={};const ui=setup(config,fields);assert.equal(ui.inputs.length,2);assert.deepEqual(config,{});
  ui.inputs[1].checked=true;ui.inputs[1].change();ui.inputs[0].checked=true;ui.inputs[0].change();assert.deepEqual(config.attachment_fields,['b','a']);
  ui.inputs[1].checked=false;ui.inputs[1].change();assert.deepEqual(config.attachment_fields,['a']);assert.equal(ui.changes(),3);
});
test('attachment selector retains excluded and missing selections until explicitly removed',()=>{
  const config={attachment_fields:['missing','private']};const ui=setup(config,[{uuid:'private',type:'file',include_email:false}]);
  assert.equal(ui.inputs.length,2);assert.ok(ui.inputs.every(input=>input.checked&&!input.disabled));
  ui.inputs[0].checked=false;ui.inputs[0].change();assert.deepEqual(config.attachment_fields,['private']);assert.equal(ui.inputs[0].disabled,true);
});
test('attachment selector caps new choices at twenty and permits removal',()=>{
  const fields=Array.from({length:21},(_,index)=>({uuid:String(index),type:'file'}));const config={attachment_fields:fields.slice(0,20).map(f=>f.uuid)};
  const ui=setup(config,fields);assert.equal(ui.inputs[20].disabled,true);ui.inputs[0].checked=false;ui.inputs[0].change();assert.equal(ui.inputs[20].disabled,false);
  ui.inputs[20].checked=true;ui.inputs[20].change();assert.equal(config.attachment_fields.length,20);assert.equal(ui.inputs[0].disabled,true);
});
