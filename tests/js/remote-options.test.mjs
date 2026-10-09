import test from 'node:test';
import assert from 'node:assert/strict';
import {RemoteOptions} from '../../src/com_nicode_form_studio/media/js/remote-options.js';

const fixture = () => {
  const node = () => ({hidden: false, textContent: '', setAttribute() {}, addEventListener() {}, before() {}});
  const form = {isConnected: true, action: 'http://localhost/index.php?option=com_nicode_form_studio&task=form.submit', dataset: {}, ownerDocument: {baseURI: 'http://localhost/', createElement: node}, querySelector: node};
  let updates = 0;
  const state = new RemoteOptions(form, [{uuid: 'province', source: {type: 'remote'}, options: [{value: 'old', label: 'Old'}]}], () => updates++, (_key, fallback) => fallback);
  return {state, form, updates: () => updates};
};

test('disposing row options invalidates in-flight callbacks and removes only its own status controls', async t => {
  const {state,updates}=fixture(); let removed=0, release;
  state.status.remove=()=>removed++; state.retry.remove=()=>removed++;
  const NativeFormData=globalThis.FormData;
  t.mock.method(globalThis,'FormData',class extends NativeFormData { constructor(){super();} });
  t.mock.method(globalThis,'fetch',()=>new Promise(resolve=>{release=resolve;}));
  state.sync({country:'ES'}); state.sync({country:'FR'}); clearTimeout(state.timer);
  const pending=state.load(state.serial);
  state.dispose();
  release({ok:true,json:async()=>({ok:true,options:{province:[{value:'late',label:'Late',enabled:true}]}})});
  await pending;
  assert.equal(removed,2); assert.equal(updates(),0); assert.equal(state.pending,false);
  assert.equal(state.resolve({uuid:'province'})[0].value,'old');
});
test('remote options discard out-of-order responses and never send file bodies', async () => {
  const {state, updates} = fixture();
  const originalFetch = globalThis.fetch, originalFormData = globalThis.FormData;
  const requests = [];
  globalThis.FormData = class { *[Symbol.iterator]() { yield ['csrf', '1']; yield ['nfs[parent]', 'ES']; yield ['file', new Blob(['private upload'])]; } };
  globalThis.fetch = (url, options) => new Promise(resolve => requests.push({url, options, resolve}));
  try {
    state.sync({country: 'ES'});
    state.sync({country: 'FR'}); clearTimeout(state.timer); const first = state.load(state.serial);
    state.sync({country: 'PT'}); clearTimeout(state.timer); const second = state.load(state.serial);
    assert.equal(requests.length, 2); assert.equal(requests[0].options.signal.aborted, true);
    assert.equal(requests[1].url.searchParams.get('task'), 'form.options');
    assert.equal(requests[1].options.credentials, 'same-origin'); assert.equal(requests[1].options.body.has('file'), false);
    requests[1].resolve({ok: true, json: async () => ({ok: true, options: {province: [{value: 'LI', label: 'Lisbon', enabled: true}]}})}); await second;
    requests[0].resolve({ok: true, json: async () => ({ok: true, options: {province: [{value: 'PA', label: 'Paris', enabled: true}]}})}); await first;
    assert.equal(state.resolve({uuid: 'province'})[0].value, 'LI'); assert.equal(state.pending, false); assert.equal(updates(), 1);
  } finally { globalThis.fetch = originalFetch; globalThis.FormData = originalFormData; clearTimeout(state.timer); }
});
test('remote option failure blocks use until a successful retry and rejects malformed option data', async () => {
  const {state} = fixture(); const originalFetch = globalThis.fetch, originalFormData = globalThis.FormData;
  globalThis.FormData = class { *[Symbol.iterator]() {} };
  try {
    state.sync({country: 'ES'}); state.sync({country: 'FR'}); clearTimeout(state.timer);
    globalThis.fetch = async () => ({ok: true, json: async () => ({ok: true, options: {province: [{value: 'X', label: {unsafe: true}}]}})});
    await state.load(state.serial); assert.equal(state.failed, true); assert.equal(state.retry.hidden, false);
    state.sync(state.values, true); clearTimeout(state.timer);
    globalThis.fetch = async () => ({ok: true, json: async () => ({ok: true, options: {province: []}})});
    await state.load(state.serial); assert.equal(state.failed, false); assert.equal(state.retry.hidden, true); assert.deepEqual(state.resolve({uuid: 'province'}), []);
  } finally { globalThis.fetch = originalFetch; globalThis.FormData = originalFormData; clearTimeout(state.timer); }
});

test('remote option default flags are typed and multi-field updates commit atomically', async () => {
  const {state} = fixture();
  state.fields.push({uuid:'second',source:{type:'remote'}});
  state.options.set('second',[{value:'old-second',label:'Old second',enabled:true}]);
  const originalFetch = globalThis.fetch, originalFormData = globalThis.FormData;
  globalThis.FormData = class { *[Symbol.iterator]() {} };
  const valid = [{value:'new',label:'New',enabled:true,default:true}];
  try {
    state.sync({country:'ES'});
    for (const invalid of [
      [{value:'x',label:'X',enabled:true,default:'true'}],
      [{value:'x',label:'X',enabled:true,default:null}],
      [{value:'x',label:'X',enabled:true,default:1}],
      [{value:'x',label:'X',enabled:true},{value:'x',label:'Duplicate',enabled:true}],
      null
    ]) {
      state.sync({country:'FR'},true); clearTimeout(state.timer);
      globalThis.fetch = async () => ({ok:true,json:async () => ({ok:true,options:{province:valid,second:invalid}})});
      await state.load(state.serial);
      assert.equal(state.failed,true); assert.equal(state.pending,false);
      assert.equal(state.resolve({uuid:'province'})[0].value,'old');
      assert.equal(state.resolve({uuid:'second'})[0].value,'old-second');
    }
    state.sync({country:'PT'},true); clearTimeout(state.timer);
    globalThis.fetch = async () => ({ok:true,json:async () => ({ok:true,options:{province:valid,second:[
      {value:'A',label:'Upper',enabled:true,default:false},{value:'a',label:'Lower',enabled:true,default:true}
    ]}})});
    await state.load(state.serial);
    assert.equal(state.failed,false); assert.equal(state.retry.hidden,true);
    assert.equal(state.resolve({uuid:'province'})[0].default,true);
    assert.deepEqual(state.resolve({uuid:'second'}).map(option=>[option.value,option.default]),[['A',false],['a',true]]);
  } finally { globalThis.fetch = originalFetch; globalThis.FormData = originalFormData; clearTimeout(state.timer); }
});

test('preview remote options use the administrator transport and retain stale-response protection', async () => {
  const {state,form,updates} = fixture(); form.dataset.nfsPreview = 'true';
  const pending = [];
  state.previewOptions = (values,signal) => new Promise(resolve => pending.push({values,signal,resolve}));
  state.sync({parent:'initial'}); state.sync({parent:'first'}); clearTimeout(state.timer);
  const first = state.load(state.serial);
  state.sync({parent:'second'}); clearTimeout(state.timer); const second = state.load(state.serial);
  assert.equal(pending[0].signal.aborted,true); assert.equal(pending[1].values.parent,'second');
  pending[1].resolve({options:{province:[{value:'new',label:'New',enabled:true,default:true}]}}); await second;
  pending[0].resolve({options:{province:[]}}); await first;
  assert.equal(state.resolve({uuid:'province'})[0].value,'new'); assert.equal(state.failed,false); assert.equal(updates(),1);
});

test('remote refresh watches declared dependencies, conditions and activity rather than unrelated answers', async () => {
  const {remoteOptionInputs} = await import('../../src/com_nicode_form_studio/media/js/remote-options.js');
  const inputs = remoteOptionInputs({fields:[
    {uuid:'country'}, {uuid:'province',source:{type:'remote',dependencies:['country']}},
    {uuid:'derived',prefill:{type:'field',field:'source'}},
    {uuid:'local',source:{type:'static',dependencies:['local-parent']}}
  ],rules:[{when:{group:'AND',children:[{field:'toggle'},{group:'OR',children:[{field:'rule-source'},{field:'toggle'}]}]}}]});
  assert.deepEqual(inputs,['country','local-parent','rule-source','source','toggle']);
  const {state} = fixture(); state.inputFields = inputs;
  try {
    state.sync({country:'ES',province:'MD',unrelated:'first'});
    state.sync({country:'ES',province:'BC',unrelated:'second'});
    assert.equal(state.serial,0); assert.equal(state.pending,false);
    state.sync({country:'FR',province:'BC'}); clearTimeout(state.timer);
    assert.equal(state.serial,1);
    state.sync({country:'FR',province:'BC',toggle:true}); clearTimeout(state.timer);
    assert.equal(state.serial,2);
    state.sync({country:'FR',toggle:true}); clearTimeout(state.timer);
    assert.equal(state.serial,3); // Remote target became inactive.
    state.sync({country:'FR',toggle:true,unrelated:'third'});
    assert.equal(state.serial,3);
    state.sync(state.values,true); clearTimeout(state.timer);
    assert.equal(state.serial,4); // Retry remains explicit even without changed inputs.
  } finally { clearTimeout(state.timer); }
});

test('remote chains refresh when a selected remote value is another source dependency', async () => {
  const {remoteOptionInputs} = await import('../../src/com_nicode_form_studio/media/js/remote-options.js');
  const {state} = fixture();
  state.inputFields = remoteOptionInputs({fields:[
    {uuid:'province',source:{type:'remote',dependencies:['country']}},
    {uuid:'city',source:{type:'remote',dependencies:['province']}}
  ],rules:[]});
  try {
    state.sync({country:'ES',province:'MD'});
    state.sync({country:'ES',province:'BC'}); clearTimeout(state.timer);
    assert.equal(state.serial,1);
    state.sync({country:'ES',province:'BC',city:'city-one'});
    assert.equal(state.serial,1); // A leaf selection cannot restart its own lookup.
  } finally { clearTimeout(state.timer); }
});
