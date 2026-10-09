import test from 'node:test';
import assert from 'node:assert/strict';
import {mountEditorTabs} from '../../src/com_nicode_form_studio/media/js/editor-tabs.js';

function fixture() {
  const activated = [], shown = [], focused = [];
  const names = ['fields','logic','preview'];
  const tabs = names.map(name => {
    const element = new EventTarget();
    return Object.assign(element, {dataset:{nfsTab:name}, attrs:{}, setAttribute(key,value) { this.attrs[key]=value; }, focus() { focused.push(name); }});
  });
  const panels = names.map(name => {
    const element = Object.assign(new EventTarget(), {dataset:{nfsTabPanel:name}, hidden:name !== 'fields', draftInput:{value:'unsaved draft'}});
    element.addEventListener('nfs:panelshown', () => shown.push(name)); return element;
  });
  const root = Object.assign(new EventTarget(), {querySelectorAll: selector => selector === '[data-nfs-tab]' ? tabs : panels, ownerDocument:{documentElement:{dir:'ltr'}}});
  const controller = mountEditorTabs(root, name => activated.push(name));
  return {tabs,panels,activated,shown,focused,controller};
}
test('switching editor sections shows exactly one panel without replacing unsaved controls', () => {
  const f=fixture(), input=f.panels[0].draftInput;
  f.tabs[1].dispatchEvent(new Event('click'));
  assert.deepEqual(f.panels.map(p=>p.hidden),[true,false,true]);
  assert.deepEqual(f.tabs.map(t=>t.attrs['aria-selected']),['false','true','false']);
  assert.deepEqual(f.tabs.map(t=>t.tabIndex),[-1,0,-1]);
  assert.deepEqual(f.shown,['logic']); assert.deepEqual(f.activated,['logic']);
  f.controller.select('fields'); assert.equal(f.panels[0].draftInput,input); assert.equal(input.value,'unsaved draft');
  assert.equal(f.controller.select('unknown'),false); assert.equal(f.panels[0].hidden,false);
});
test('programmatic preview reveal cannot recursively trigger preview generation', () => {
  const f=fixture(); f.controller.select('preview'); f.controller.select('preview');
  assert.deepEqual(f.shown,['preview']); assert.deepEqual(f.activated,[]);
  f.tabs[2].dispatchEvent(new Event('click')); assert.deepEqual(f.activated,['preview']);
});
test('keyboard arrows move focus without loading a preview; Home and End reach the boundaries', () => {
  const f=fixture();
  for (const [index,key] of [[0,'ArrowLeft'],[2,'Home'],[0,'End']]) {
    const event=new Event('keydown',{cancelable:true}); Object.defineProperty(event,'key',{value:key}); f.tabs[index].dispatchEvent(event); assert.equal(event.defaultPrevented,true);
  }
  assert.deepEqual(f.focused,['preview','fields','preview']); assert.deepEqual(f.activated,[]);
  assert.deepEqual(f.tabs.map(t=>t.tabIndex),[-1,-1,0]);
});
