import test from 'node:test';
import assert from 'node:assert/strict';
import {emailFields,emailFormat,emailInsertion,insertEmailText} from '../../src/com_nicode_form_studio/media/js/email-editor.js';
test('email picker follows server inclusion policy, including explicit sensitive opt-in',()=>{
  const fields=[{uuid:'a',type:'text'},{uuid:'b',type:'text',sensitive:true},{uuid:'c',type:'password',include_email:true},{uuid:'d',type:'text',include_email:false},{uuid:'e',type:'text',sensitive:true,include_email:true}];
  assert.deepEqual(emailFields(fields).map(f=>f.uuid),['a','e']);
});
test('field and whole-response insertions retain tokens and HTML line breaks',()=>{
  assert.equal(emailInsertion({uuid:'a'}),'{{field.a.label}}: {{field.a.option_label}}\n');
  assert.equal(emailInsertion({uuid:'a'},false,false),'{{field.a.option_label}}\n');
  assert.equal(emailInsertion(null),'{{response.summary}}\n');
  assert.match(emailInsertion(null,true),/^<pre.*>{{response.summary}}<\/pre>\n$/);
});
test('format honors explicit plain text while preserving existing HTML configuration',()=>{
  assert.equal(emailFormat({}),'text'); assert.equal(emailFormat({body_html:'<p>Saved</p>'}),'html');
  assert.equal(emailFormat({email_format:'text',body_html:'<p>Saved</p>'}),'text');
});
test('insertion replaces the selected text and dispatches a draft input change',()=>{
  let changed=0,focused=false,selection;
  const input={value:'Hello replace end',selectionStart:6,selectionEnd:13,dispatchEvent:e=>{assert.equal(e.type,'input');changed++;},focus:()=>focused=true,setSelectionRange:(...values)=>selection=values};
  insertEmailText(input,'{{response.summary}}');
  assert.equal(input.value,'Hello {{response.summary}} end'); assert.equal(changed,1); assert.equal(focused,true); assert.deepEqual(selection,[26,26]);
});
