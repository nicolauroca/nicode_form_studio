import test from 'node:test';
import assert from 'node:assert/strict';
import {recoverRuleFocus} from '../../src/com_nicode_form_studio/media/js/focus-recovery.js';

function fixture() {
  const focused = [], nodes = Array.from({length:4},(_,index)=>({isConnected:true,index,disabled:false,type:'text',hidden:false,
    closest() { return this.hidden ? {} : null; },
    compareDocumentPosition(other) { return other.index > index ? 4 : 2; },
    focus() { focused.push(index); },
  }));
  const form = {ownerDocument:{activeElement:nodes[1]},contains:node=>nodes.includes(node),querySelectorAll:()=>nodes,querySelector:()=>({focus:()=>focused.push('result')})};
  return {nodes,form,focused};
}

test('rule focus recovery skips hidden disabled and hidden-input successors in DOM order', () => {
  const {nodes,form,focused} = fixture();
  recoverRuleFocus(form,nodes[1]); assert.deepEqual(focused,[]);
  nodes[1].hidden = true; nodes[2].disabled = true;
  recoverRuleFocus(form,nodes[1]); assert.deepEqual(focused,[3]);
  focused.length = 0; nodes[3].type = 'hidden';
  recoverRuleFocus(form,nodes[1]); assert.deepEqual(focused,[0]);
  focused.length = 0; nodes[0].disabled = true;
  recoverRuleFocus(form,nodes[1]); assert.deepEqual(focused,['result']);
});

test('rule recovery preserves explicit widget focus and never acts without an affected field', () => {
  const {nodes,form,focused} = fixture();
  nodes[1].hidden = true; form.ownerDocument.activeElement = nodes[2];
  recoverRuleFocus(form,nodes[1]); assert.deepEqual(focused,[]);
  recoverRuleFocus(form,null); assert.deepEqual(focused,[]);
  nodes[1].isConnected = false; form.ownerDocument.activeElement = {};
  recoverRuleFocus(form,nodes[1]); assert.deepEqual(focused,[0]);
});
