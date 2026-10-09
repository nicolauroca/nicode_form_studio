import test from 'node:test';
import assert from 'node:assert/strict';
import {loadBrowserProviders, loadBrowserStyles, browserCall} from '../../src/com_nicode_form_studio/media/js/browser-providers.js';
import {evaluateOperator, applyEffect, evaluateRules} from '../../src/com_nicode_form_studio/media/js/rules.js';
import {normalizeField, validateField, validateRelations} from '../../src/com_nicode_form_studio/media/js/validation.js';

const module = {providers:{
  fields:{fixture_upper:{version:'1.2.0', read:controls => controls[0].value, update:(controls, field, state) => { controls[0].value = state.value ?? ''; }, normalize:raw => raw == null ? null : String(raw).trim().toUpperCase(), validate:(value, config) => config.required && !value ? ['required'] : []}},
  operators:{fixture_suffix:{version:'1.2.0', evaluate:(left, right) => typeof left === 'string' && left.endsWith(right)}},
  effects:{fixture_prefix:{version:'1.2.0', apply:(state, config) => { state.value = config.prefix + (state.value ?? ''); return state; }}},
  validators:{fixture_forbidden:{version:'1.2.0', validate:(values, config) => Object.fromEntries(Object.entries(values).filter(([,value]) => value === config.forbidden).map(([uuid]) => [uuid, ['forbidden']]))}},
}};
const manifest = Object.fromEntries(Object.entries(module.providers).map(([kind, entries]) => [kind, Object.fromEntries(Object.keys(entries).map(id => [id, {version:'1.2.0', module:'/installed/fixture.js'}]))]));
const importer = async () => module;

test('installed browser providers execute normalization, validation, operators and effects without leaking mutations', async () => {
  const providers = await loadBrowserProviders(manifest, importer);
  const field = {uuid:'a', type:'fixture_upper', config:{required:true}};
  assert.equal(normalizeField(field, '  abc  ', 'text', providers), 'ABC');
  assert.deepEqual(validateField(field, null, 'text', {active:true, required:true}, providers), ['required']);
  assert.equal(evaluateOperator('fixture_suffix', 'ABC', 'BC', 'text', providers), true);
  assert.equal(evaluateOperator('fixture_suffix', null, 'BC', 'text', providers), false);
  const state = {visible:true, enabled:true, required:false, active:true, value:'ABC', options:[]};
  assert.equal(applyEffect(state, {type:'fixture_prefix', prefix:'x'}, providers).value, 'xABC');
  assert.equal(state.value, 'ABC');
  assert.deepEqual(validateRelations({fields:[field], validators:[{type:'fixture_forbidden', config:{forbidden:'ABC'}}]}, {a:'ABC'}, {a:'text'}, {a:state}, providers), {a:['forbidden']});
  assert.ok(Object.isFrozen(providers.fields.fixture_upper));
});

test('a nested custom condition and target effect stabilize independently per instance', async () => {
  const providers = await loadBrowserProviders(manifest, importer);
  const spec = {elements:[{uuid:'a'}, {uuid:'b'}], fields:[{uuid:'a', type:'text'}, {uuid:'b', type:'text'}], rules:[{uuid:'r', when:{group:'AND', children:[{field:'a', operator:'fixture_suffix', value:'C'}]}, effects:[{target:'b', type:'fixture_prefix', prefix:'x'}]}]};
  assert.equal(evaluateRules(spec, {a:'ABC', b:'value'}, {a:'text', b:'text'}, 64, null, providers).values.b, 'xvalue');
  assert.equal(evaluateRules(spec, {a:'other', b:'value'}, {a:'text', b:'text'}, 64, null, providers).values.b, 'value');
});

test('missing modules, incompatible versions, missing hooks and core replacement fail closed', async () => {
  await assert.rejects(loadBrowserProviders(manifest, async () => { throw new Error('missing'); }));
  await assert.rejects(loadBrowserProviders({operators:{fixture_suffix:{module:'fixture', version:'2.0.0'}}}, importer));
  await assert.rejects(loadBrowserProviders({operators:{fixture_suffix:{module:'fixture', version:'1.2.0'}}}, async () => ({providers:{operators:{fixture_suffix:{version:'1.2.0'}}}})));
  await assert.rejects(loadBrowserProviders({operators:{equals:{module:'fixture', version:'1.2.0'}}}, importer));
  assert.throws(() => browserCall({evaluate:async () => true}, 'evaluate', null));
  const healthy = await loadBrowserProviders(manifest, importer);
  assert.equal(evaluateOperator('fixture_suffix', 'ABC', 'C', 'text', healthy), true);
});

test('provider styles load once per document and failures are isolated from other documents', async () => {
  const document = failure => ({links:[], createElement:() => ({}), get head() { return {append:link => { this.links.push(link); queueMicrotask(() => failure ? link.onerror() : link.onload()); }}; }});
  const manifest = {operators:{custom:{styles:['/fixture.css']}, another:{styles:['/fixture.css']}}};
  const first = document(false), preview = document(false), broken = document(true);
  await Promise.all([loadBrowserStyles(manifest, first), loadBrowserStyles(manifest, first)]);
  await loadBrowserStyles(manifest, preview);
  assert.equal(first.links.length, 1); assert.equal(preview.links.length, 1);
  await assert.rejects(loadBrowserStyles(manifest, broken));
  await loadBrowserStyles(manifest, first);
  await assert.rejects(loadBrowserStyles({fields:{custom:{styles:'invalid'}}}, first));
});

test('derived copies normalize through destination providers and retain closed recoverable errors', () => {
  const spec = {elements:[{uuid:'a'},{uuid:'b'}],fields:[
    {uuid:'a',type:'text',config:{trim:false}},
    {uuid:'b',type:'fixture_copy',config:{readonly:true},prefill:{type:'field',field:'a'}}
  ],rules:[]};
  const providers = {fields:{fixture_copy:{normalize:value => {
    if (value === 'reject') throw new TypeError('Private provider detail');
    return value == null ? null : String(value).trim().toUpperCase();
  }}}};
  const run = value => evaluateRules(spec,{a:value,b:null},{a:'text',b:'text'},64,null,providers);
  assert.equal(run(' trimmed ').values.b,'TRIMMED');
  assert.deepEqual(run('reject').normalizationErrors,{b:['type']});
  assert.equal(run('reject').values.b,null);
  assert.deepEqual(run('valid').normalizationErrors,{});
  for (const type of ['set_value','clear_value']) {
    spec.rules = [{uuid:'r',when:{field:'a',operator:'equals',value:'reject'},effects:[{target:'b',type,value:'recovered'}]}];
    const result = run('reject');
    assert.deepEqual(result.normalizationErrors,{});
    assert.equal(result.values.b,type === 'set_value' ? 'recovered' : null);
  }
  spec.rules = []; spec.fields[1].type = 'text';
  assert.equal(run(' trimmed ').values.b,'trimmed');
});
