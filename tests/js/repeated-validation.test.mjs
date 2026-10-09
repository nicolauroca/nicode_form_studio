import test from 'node:test';
import assert from 'node:assert/strict';
import {repeatedErrors} from '../../src/com_nicode_form_studio/media/js/repeated-validation.js';
import {FormInstance} from '../../src/com_nicode_form_studio/media/js/form-instance.js';
import {updateFieldError} from '../../src/com_nicode_form_studio/media/js/field-errors.js';

test('repeated minima follow each active scope and malformed declarations fail closed', () => {
  const spec={elements:[{uuid:'outer/one/group',type:'repeatable-group',repeat:{min:1,max:2}},{uuid:'outer/two/group',type:'repeatable-group',repeat:{min:1,max:2}}],instances:{'outer/one/group':[],'outer/two/group':[]}};
  const states={'outer/one/group':{active:true},'outer/two/group':{active:false}};
  assert.deepEqual(repeatedErrors(spec,states),{'outer/one/group':['min_instances']});
  states['outer/one/group'].active=false;
  assert.deepEqual(repeatedErrors(spec,states),{});
  for(const invalid of [null,[],{}, {'outer/one/group':['a','b','c'],'outer/two/group':[]}]) assert.throws(()=>repeatedErrors({...spec,instances:invalid},states),TypeError);
  assert.throws(()=>repeatedErrors(spec,{}),TypeError);
  assert.deepEqual(repeatedErrors({elements:[]},{}),{});
});

test('form validation reports group minima only on the selected step and clears inactive errors', () => {
  const node={}, other={contains:()=>false}, current={contains:item=>item===node};
  const instance={spec:{elements:[{uuid:'group',type:'repeatable-group',repeat:{min:1,max:2}}],instances:{group:[]},fields:[],validators:[],datatypes:{}},
    state:{states:{group:{active:true}},values:{}},refresh(){},remoteOptions:{pending:false,failed:false},nodes:new Map([['group',node]]),controls:new Map(),normalizationErrors:{},steps:[other,current],message:(key,fallback)=>fallback,showErrors(errors){this.errors=errors;}};
  assert.equal(FormInstance.prototype.validateValues.call(instance,other),true);
  assert.equal(FormInstance.prototype.validateValues.call(instance,current),false);
  assert.deepEqual(instance.errors,{group:['Add the required number of entries.']});
  instance.state.states.group.active=false;
  assert.equal(FormInstance.prototype.validateValues.call(instance),true);
  assert.deepEqual(instance.errors,{});
});

test('a container error never overwrites an error belonging to a descendant field', () => {
  const child={textContent:'Existing field error',hidden:false},own={id:'group-error',textContent:'',hidden:true};
  const node={querySelector:selector=>selector==='[data-nfs-error="group"]'?own:child};
  updateFieldError('form','group',node,[],['Add a row']);
  assert.equal(own.textContent,'Add a row'); assert.equal(own.hidden,false);
  assert.equal(child.textContent,'Existing field error');
  updateFieldError('form','group',node,[],[]);
  assert.equal(own.hidden,true); assert.equal(child.textContent,'Existing field error');
});
