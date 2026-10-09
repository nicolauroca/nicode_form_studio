import test from 'node:test';
import assert from 'node:assert/strict';
import {rowRequestData, retainedRowNodes, updateRowButtons, editRow} from '../../src/com_nicode_form_studio/media/js/row-editor.js';

const group='11111111-1111-4111-8111-111111111111', one='22222222-2222-4222-8222-222222222222', two='33333333-3333-4333-8333-333333333333', field='44444444-4444-4444-8444-444444444444';
const a=`${group}/${one}/${field}`, b=`${group}/${two}/${field}`;

test('row edit transport preserves packed answers and declarations but excludes upload bytes', t=>{
  const NativeFormData=globalThis.FormData;
  t.mock.method(globalThis,'FormData',class extends NativeFormData {
    constructor(){ super(); this.set(`nfs[${a}]`,'changed'); this.set(`nfs_files[${b}]`,new Blob(['private bytes']),'private.txt'); this.set('nfs_instances',JSON.stringify({[group]:[one,two]})); this.set('attempt','same'); }
  });
  const body=rowRequestData({},JSON.stringify({operation:'add',group}));
  assert.equal(body.get('attempt'),'same'); assert.equal(JSON.parse(body.get('nfs_values'))[a],'changed');
  assert.equal(body.has(`nfs_files[${b}]`),false); assert.equal(body.toString().includes('private'),false);
  assert.deepEqual(JSON.parse(body.get('nfs_instances')),{[group]:[one,two]});
});

test('retained row controls keep their exact node identity and reject duplicate or changed definitions',()=>{
  const original={files:[{name:'keep.txt'}],value:'latest during request'}, newA={},newB={};
  const previous=[{uuid:a,type:'file'}], next=[...previous,{uuid:b,type:'file'}];
  const oldNodes=new Map([[a,original]]), newNodes=new Map([[a,newA],[b,newB]]);
  assert.deepEqual(retainedRowNodes(previous,next,oldNodes,newNodes),[[newA,original]]);
  assert.throws(()=>retainedRowNodes(previous,[...next,next[0]],oldNodes,newNodes));
  assert.throws(()=>retainedRowNodes(previous,[{uuid:a,type:'text'}],oldNodes,newNodes));
  assert.deepEqual(retainedRowNodes(previous,[next[1]],oldNodes,newNodes),[]);
});

test('row buttons reflect group bounds and block concurrent submission or unavailable configuration',()=>{
  const add={value:JSON.stringify({operation:'add',group})}, remove={value:JSON.stringify({operation:'remove',group,row:one})};
  const instance={form:{dataset:{},querySelectorAll:()=>[add,remove]},spec:{elements:[{uuid:group,repeat:{min:1,max:2}}],instances:{[group]:[one]}}};
  updateRowButtons(instance); assert.equal(add.disabled,false); assert.equal(remove.disabled,true);
  instance.spec.instances[group].push(two); updateRowButtons(instance); assert.equal(add.disabled,true); assert.equal(remove.disabled,false);
  instance.pending=true; updateRowButtons(instance); assert.ok(add.disabled && remove.disabled);
});

test('row edit rejects duplicate clicks before constructing any transport', async()=>{
  await editRow({pending:true},{get disabled(){assert.fail('Pending operation inspected button');}});
});

test('preview row edits require an administrative transport and never unlock submission after rejection', async t=>{
  const NativeFormData=globalThis.FormData;
  t.mock.method(globalThis,'FormData',class extends NativeFormData { constructor(){super();this.set(`nfs[${a}]`,'kept');this.set('nfs_instances',JSON.stringify({[group]:[one]}));} });
  t.mock.method(globalThis,'fetch',()=>assert.fail('Preview attempted the public row endpoint'));
  const add={value:JSON.stringify({operation:'add',group})}, submit={disabled:true};
  let calls=0;
  const instance={pending:false,configurationError:false,remoteOptions:{pending:false,failed:false},
    form:{dataset:{nfsPreview:'true'},querySelectorAll:()=>[add],querySelector:()=>submit,setAttribute(){},removeAttribute(){}},
    spec:{elements:[{uuid:group,repeat:{min:0,max:2}}],instances:{[group]:[one]}},
    result:{textContent:'',focus(){}},message:(_key,fallback)=>fallback,
  };
  updateRowButtons(instance); assert.equal(add.disabled,true);
  instance.previewRows=async body=>{calls++;assert.equal(JSON.parse(body.get('nfs_values'))[a],'kept');return {ok:false};};
  updateRowButtons(instance); assert.equal(add.disabled,false);
  await editRow(instance,add);
  assert.equal(calls,1); assert.equal(submit.disabled,true); assert.equal(instance.pending,false); assert.equal(add.disabled,false);
});
