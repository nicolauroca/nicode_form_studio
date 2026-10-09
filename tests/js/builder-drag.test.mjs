import test from 'node:test';
import assert from 'node:assert/strict';
import {dropElement} from '../../src/com_nicode_form_studio/media/js/builder-model.js';
import {mountBuilderDrag} from '../../src/com_nicode_form_studio/media/js/builder-drag.js';

const fixture = () => ({elements:[
  {uuid:'a',type:'section',parent_uuid:null},
  {uuid:'b',type:'group',parent_uuid:'a'},
  {uuid:'field',type:'field',parent_uuid:'b'},
  {uuid:'c',type:'step',parent_uuid:null},
  {uuid:'d',type:'step',parent_uuid:null},
],fields:[{uuid:'field',config:{custom:{version:2}}}],rules:[{target:'field'}]});

test('drop placement preserves descendants and provider data across sibling and parent moves', () => {
  const draft = fixture(), fields = JSON.stringify(draft.fields), rules = JSON.stringify(draft.rules);
  dropElement(draft,'b','c','inside');
  assert.equal(draft.elements.find(e=>e.uuid==='b').parent_uuid,'c');
  assert.equal(draft.elements.find(e=>e.uuid==='field').parent_uuid,'b');
  dropElement(draft,'d','a','before');
  assert.deepEqual(draft.elements.filter(e=>e.parent_uuid===null).map(e=>e.uuid),['d','a','c']);
  dropElement(draft,'d','c','after');
  assert.deepEqual(draft.elements.filter(e=>e.parent_uuid===null).map(e=>e.uuid),['a','c','d']);
  dropElement(draft,'b',null,'inside');
  assert.equal(draft.elements.at(-1).uuid,'b'); assert.equal(draft.elements.at(-1).parent_uuid,null);
  assert.equal(JSON.stringify(draft.fields),fields); assert.equal(JSON.stringify(draft.rules),rules);
  for (const [source,target,position] of [['b','field','inside'],['c','d','inside'],['a','missing','before'],['c',null,'before'],['c','c','after']]) {
    const before = JSON.stringify(draft); assert.throws(()=>dropElement(draft,source,target,position)); assert.equal(JSON.stringify(draft),before);
  }
});

test('drag adapter ignores external payloads and invalid destinations then resets its local source', () => {
  const draft = fixture(), handlers = {}, moves = [];
  const rows = Object.fromEntries(draft.elements.map(e=>[e.uuid,{dataset:{nfsTreeItem:e.uuid},getBoundingClientRect:()=>({top:0,height:100}),removeAttribute(){delete this.dataset.nfsDrop;}}]));
  const tree = {addEventListener:(name,fn)=>{handlers[name]=fn;},contains:row=>Object.values(rows).includes(row),querySelectorAll:()=>Object.values(rows)};
  const event = (id,y=50) => ({target:{closest:()=>rows[id]},clientY:y,dataTransfer:{setData(){}},preventDefault(){this.prevented=true;}});
  mountBuilderDrag(tree,draft,id=>moves.push(id));
  handlers.drop(event('a')); assert.deepEqual(moves,[]);
  handlers.dragstart(event('b'));
  const invalid = event('field'); handlers.dragover(invalid); assert.equal(invalid.prevented,undefined);
  const accepted = event('c'); handlers.dragover(accepted); assert.equal(accepted.prevented,true); assert.equal(rows.c.dataset.nfsDrop,'inside');
  handlers.drop(event('c')); assert.deepEqual(moves,['b']); assert.equal(draft.elements.find(e=>e.uuid==='b').parent_uuid,'c');
  handlers.drop(event('a')); assert.deepEqual(moves,['b']);
  handlers.dragstart(event('d')); handlers.dragend(); handlers.drop(event('a')); assert.deepEqual(moves,['b']);
});

test('drag adapter distinguishes row edges from container centres and supports the root destination', () => {
  const draft = fixture(), handlers = {};
  const rows = Object.fromEntries(draft.elements.map(e=>[e.uuid,{dataset:{nfsTreeItem:e.uuid},getBoundingClientRect:()=>({top:100,height:100}),removeAttribute(){delete this.dataset.nfsDrop;}}]));
  rows.root = {dataset:{nfsTreeRoot:''},getBoundingClientRect:()=>({top:0,height:40}),removeAttribute(){delete this.dataset.nfsDrop;}};
  const tree = {addEventListener:(name,fn)=>{handlers[name]=fn;},contains:row=>Object.values(rows).includes(row),querySelectorAll:()=>Object.values(rows)};
  const event = (id,y) => ({target:{closest:()=>rows[id]},clientY:y,dataTransfer:{setData(){}},preventDefault(){this.prevented=true;}});
  mountBuilderDrag(tree,draft,()=>{});
  handlers.dragstart(event('field',150));
  const enterRoot = event('root',20); handlers.dragenter(enterRoot);
  assert.equal(enterRoot.prevented,true);
  handlers.dragover(event('root',20));
  assert.equal(rows.root.dataset.nfsDrop,'inside'); handlers.drop(event('root',20));
  assert.equal(draft.elements.at(-1).uuid,'field'); assert.equal(draft.elements.at(-1).parent_uuid,null);
  for (const [y,position] of [[110,'before'],[190,'after']]) {
    handlers.dragstart(event('field',150)); handlers.dragover(event('c',y));
    assert.equal(rows.c.dataset.nfsDrop,position); handlers.drop(event('c',y));
    const ids = draft.elements.map(e=>e.uuid);
    assert.equal(ids.indexOf('field'),ids.indexOf('c') + (position==='before'?-1:1));
  }
  handlers.dragstart(event('field',150)); handlers.dragover(event('c',150));
  assert.equal(rows.c.dataset.nfsDrop,'inside'); handlers.drop(event('c',150));
  assert.equal(draft.elements.find(e=>e.uuid==='field').parent_uuid,'c');
});
