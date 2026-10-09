import test from 'node:test';
import assert from 'node:assert/strict';
import {authoringContainers} from '../../src/com_nicode_form_studio/media/js/builder-model.js';

test('repeatable authoring creates editable bounded definitions and preserves nested identity', () => {
  assert.equal(authoringContainers.includes('repeatable-group'), true);
  const draft = {elements:[], fields:[]};
  addElement(draft, 'repeatable-group', false, null, 'repeat');
  addElement(draft, 'repeatable-group', false, 'repeat', 'nested');
  addElement(draft, 'text', true, 'nested', 'answer');
  assert.deepEqual(draft.elements[0],{uuid:'repeat',type:'repeatable-group',parent_uuid:null,repeat:{min:1,max:5}});
  draft.elements[1].repeat.max=2;
  assert.equal(draft.elements[0].repeat.max,5);
  reparentElement(draft,'answer','repeat');
  assert.equal(draft.fields[0].uuid,'answer');
  assert.throws(()=>reparentElement(draft,'repeat','nested'));
  removeElement(draft,'nested');
  assert.deepEqual(draft.elements.map(e=>e.uuid),['repeat','answer']);
  assert.equal(draft.fields.length,1);
});
import {addElement, descendants, moveElement, reparentElement, removeElement, configurationObject, supportsStepParent, insertionParent, revealAncestors} from '../../src/com_nicode_form_studio/media/js/builder-model.js';

test('revealing a selected descendant expands only its ancestors without changing draft data', () => {
  const draft = {elements:[{uuid:'a',type:'section'},{uuid:'b',type:'group',parent_uuid:'a'},{uuid:'field',type:'field',parent_uuid:'b'},{uuid:'other',type:'section'}]};
  const before = JSON.stringify(draft), collapsed = new Set(['a','b','other']);
  revealAncestors(draft,'field',collapsed);
  assert.deepEqual([...collapsed],['other']); assert.equal(JSON.stringify(draft),before);
  revealAncestors(draft,'missing',collapsed); assert.deepEqual([...collapsed],['other']);
  draft.elements[0].parent_uuid = 'b'; collapsed.add('a'); collapsed.add('b');
  revealAncestors(draft,'field',collapsed); assert.deepEqual([...collapsed],['other']);
});

test('step insertion and reparenting preserve a navigable page sequence through nested groups', () => {
  const draft = {elements:[],fields:[]};
  addElement(draft,'section',false,null,'root');
  addElement(draft,'step',false,'root','first');
  addElement(draft,'group',false,'first','group');
  addElement(draft,'text',true,'group','answer');
  assert.equal(insertionParent(draft,'step','first'),'root');
  assert.equal(insertionParent(draft,'step','answer'),'root');
  assert.equal(insertionParent(draft,'text','group'),'group');
  addElement(draft,'step',false,insertionParent(draft,'step','answer'),'second');
  assert.equal(supportsStepParent(draft,'second','group'),false);
  assert.throws(()=>reparentElement(draft,'second','group'));
  assert.equal(draft.elements.find(e=>e.uuid==='second').parent_uuid,'root');
  addElement(draft,'section',false,null,'wrapper');
  reparentElement(draft,'second','wrapper');
  assert.equal(supportsStepParent(draft,'wrapper','group'),false);
  assert.throws(()=>reparentElement(draft,'wrapper','first'));
  const before = JSON.stringify(draft);
  assert.throws(()=>addElement(draft,'step',false,'group','invalid'));
  assert.equal(JSON.stringify(draft),before);
  reparentElement(draft,'group','second');
  assert.equal(draft.elements.find(e=>e.uuid==='group').parent_uuid,'second');
});

test('nested layout edits preserve identity, provider configuration and sibling ordering', () => {
  const draft = {elements: [], fields: [], rules: [{target: 'answer'}], actions: []};
  addElement(draft, 'section', false, null, 'section');
  addElement(draft, 'text', true, 'section', 'answer');
  addElement(draft, 'email', true, 'section', 'email');
  addElement(draft, 'text', true, null, 'outside');
  draft.fields[0].config.customProvider = {version: 2};
  assert.equal(moveElement(draft, 'email', -1), true);
  assert.deepEqual(draft.elements.filter(item => item.parent_uuid === 'section').map(item => item.uuid), ['email', 'answer']);
  assert.equal(moveElement(draft, 'email', -1), false);
  reparentElement(draft, 'answer', null);
  assert.deepEqual(draft.fields[0].config.customProvider, {version: 2});
  assert.deepEqual([...descendants(draft, 'section')], ['section', 'email']);
  removeElement(draft, 'section');
  assert.deepEqual(draft.elements.map(item => item.uuid), ['answer', 'outside']);
  assert.deepEqual(draft.fields.map(item => item.uuid), ['answer', 'outside']);
  assert.deepEqual(draft.rules, [{target: 'answer'}]);
  assert.equal(new Set(draft.fields.map(item => item.name)).size, 2);
});

test('layout edits reject cycles and non-container parents without corrupting the draft', () => {
  const draft = {elements: [], fields: []};
  addElement(draft, 'section', false, null, 'a');
  addElement(draft, 'group', false, 'a', 'b');
  addElement(draft, 'text', true, 'b', 'field');
  const before = structuredClone(draft);
  for (const parent of ['b', 'field', 'unknown', 'a']) assert.throws(() => reparentElement(draft, 'a', parent));
  assert.throws(() => addElement(draft, 'email', true, 'field', 'new'));
  assert.deepEqual(draft, before);
  assert.equal(moveElement(draft, 'a', 5), false);
});

test('removing a container deletes its fields but keeps rule references for compiler diagnostics', () => {
  const draft = {elements: [], fields: [], rules: [{effects: [{target: 'child'}]}]};
  addElement(draft, 'section', false, null, 'a');
  addElement(draft, 'password', true, 'a', 'child');
  assert.equal(draft.fields[0].persist, false);
  removeElement(draft, 'a');
  assert.equal(draft.elements.length, 0); assert.equal(draft.fields.length, 0);
  assert.equal(draft.rules[0].effects[0].target, 'child');
});

test('editing an empty PHP configuration survives JSON serialization after reopening', () => {
  const config = configurationObject(JSON.parse('[]'));
  config.subject = 'Draft subject';
  assert.deepEqual(JSON.parse(JSON.stringify(config)), {subject: 'Draft subject'});
  assert.deepEqual(configurationObject({provider: {version: 3}}), {provider: {version: 3}});
});
