import test from 'node:test';
import assert from 'node:assert/strict';
import {FormInstance} from '../../src/com_nicode_form_studio/media/js/form-instance.js';

test('rule refresh retains the current step identity and chooses an ordered fallback when it disappears', () => {
  const nodes = ['a','b','c','d'].map(uuid => ({dataset:{nfsStep:uuid},setAttribute(name,value){this[name]=value;}}));
  const buttons = Object.fromEntries(['previous','next','submit','progress'].map(name=>[name,{}]));
  const instance = {
    spec:{fields:[],elements:nodes.map(node=>({uuid:node.dataset.nfsStep,type:'step'})),rules:[],datatypes:{}},
    nodes:new Map(nodes.map(node=>[node.dataset.nfsStep,node])), controls:new Map(), providers:{},
    form:{dataset:{},querySelectorAll:()=>nodes,querySelector:selector=>buttons[selector.match(/nfs-(.*)\]/)[1]]},
    remoteOptions:{sync(){},pending:false,failed:false},result:{textContent:''},
    updateSteps:FormInstance.prototype.updateSteps,currentStep:1,steps:[...nodes],
  };
  const refresh = () => { FormInstance.prototype.refresh.call(instance); assert.equal(instance.configurationError,false); };
  const current = () => instance.steps[instance.currentStep]?.dataset.nfsStep;
  instance.spec.elements[0].visible = false; refresh();
  assert.equal(current(),'b'); assert.equal(buttons.progress.textContent,'1 / 3');
  instance.spec.elements[0].visible = true; refresh();
  assert.equal(current(),'b'); assert.equal(buttons.progress.textContent,'2 / 4');
  instance.spec.elements[1].visible = false; refresh();
  assert.equal(current(),'c');
  instance.spec.elements[2].visible = false; instance.spec.elements[3].visible = false; refresh();
  assert.equal(current(),'a'); assert.equal(buttons.submit.hidden,false);
  instance.spec.elements[0].visible = false; refresh();
  assert.equal(current(),undefined); assert.equal(instance.currentStep,0);
  instance.spec.elements[2].visible = true; refresh();
  assert.equal(current(),'c');
  // The success reset clears navigation identity before evaluating newly active steps.
  instance.steps = []; instance.currentStep = 0; instance.spec.elements[0].visible = true; refresh();
  assert.equal(current(),'a');
});

function fixture(type, values, count = 1) {
  const nodes = new Map(['a', 'b'].map(uuid => [uuid, {uuid}]));
  const steps = ['a', 'b'].map(uuid => ({contains:node => node?.uuid === uuid}));
  let displayed;
  const instance = {
    refresh() {}, configurationError:false, remoteOptions:{pending:false,failed:false},
    spec:{fields:['a', 'b'].map(uuid => ({uuid,type:'checkbox',config:{}})), datatypes:{a:'boolean',b:'boolean'}, validators:[{type,config:{fields:['a','b'],count}}]},
    state:{values,states:Object.fromEntries(['a','b'].map(uuid => [uuid,{active:true,required:false,value:values[uuid]}]))},
    normalizationErrors:{}, providers:{}, nodes, steps,
    controls:new Map(['a','b'].map(uuid => [uuid,[]])),
    message:(_key,fallback)=>fallback, showErrors:errors=>{ displayed = errors; },
  };
  return {instance,steps,errors:()=>displayed};
}

test('Next can reach a future boolean confirmation but later navigation and final submission enforce it', () => {
  const f = fixture('confirmation',{a:true,b:false});
  assert.equal(FormInstance.prototype.validateValues.call(f.instance,f.steps[0]),true);
  assert.deepEqual(f.errors(),{});
  assert.equal(FormInstance.prototype.validateValues.call(f.instance,f.steps[1]),false);
  assert.deepEqual(Object.keys(f.errors()),['b']);
  assert.equal(FormInstance.prototype.validateValues.call(f.instance),false);
  assert.deepEqual(Object.keys(f.errors()),['a','b']);
  f.instance.state.values.b = true; f.instance.state.states.b.value = true;
  assert.equal(FormInstance.prototype.validateValues.call(f.instance),true);
});

test('presence across steps permits reaching the later choice and remains mandatory at the end', () => {
  for (const type of ['at_least_one','exactly_n']) {
    const f = fixture(type,{a:false,b:false});
    assert.equal(FormInstance.prototype.validateValues.call(f.instance,f.steps[0]),true);
    assert.equal(FormInstance.prototype.validateValues.call(f.instance),false);
    f.instance.state.values.b = true; f.instance.state.states.b.value = true;
    assert.equal(FormInstance.prototype.validateValues.call(f.instance,f.steps[1]),true);
    assert.equal(FormInstance.prototype.validateValues.call(f.instance),true);
  }
});

test('same-step comparisons remain blocking and inactive later steps are not deferred', () => {
  const f = fixture('confirmation',{a:true,b:false});
  f.steps[0].contains = node => ['a','b'].includes(node?.uuid);
  f.steps[1].contains = () => false;
  assert.equal(FormInstance.prototype.validateValues.call(f.instance,f.steps[0]),false);
  assert.deepEqual(Object.keys(f.errors()),['a','b']);
  f.instance.steps = [f.steps[0]];
  delete f.instance.state.values.b; f.instance.state.states.b.active = false;
  assert.equal(FormInstance.prototype.validateValues.call(f.instance,f.steps[0]),true);
});
