import test from 'node:test';
import assert from 'node:assert/strict';
import {mountTranslationEditor} from '../../src/com_nicode_form_studio/media/js/translation-editor.js';

test('saved translations render at mount and refresh field references without changing the draft', () => {
  const tab = new EventTarget();
  const node = (tag, text, attributes = {}) => Object.assign(new EventTarget(), {
    tag, text, attributes, children: [], append(...items) { this.children.push(...items); },
    replaceChildren(...items) { this.children = items; }, closest() { return tab; },
  });
  const host = node('div'), root = {querySelector: () => host};
  const draft = {name:'Saved form',base_language:'en-GB',fields:[{uuid:'email',name:'email',config:{label:'Email'}}],elements:[],rules:[],actions:[],translations:{'es-ES':{fields:{email:{label:'Correo'}}}}};
  const original = structuredClone(draft); let changes = 0;
  const editor = mountTranslationEditor(root, {draft, changed:()=>changes++, node, button:text=>node('button',text),t:key=>key});
  const descendants = element => [element,...element.children.flatMap(descendants)];
  const label = () => descendants(host).find(item=>item.attributes['data-nfs-translation-path'] === JSON.stringify(['fields','email','label']));
  assert.equal(editor.locale(),'es-ES'); assert.equal(label().value,'Correo'); assert.equal(label().placeholder,'Email');
  assert.deepEqual(draft,original); assert.equal(changes,0);
  draft.fields[0].config.label='Contact email';
  tab.dispatchEvent(new Event('nfs:panelshown'));
  assert.equal(label().value,'Correo'); assert.equal(label().placeholder,'Contact email'); assert.equal(changes,0);
});
