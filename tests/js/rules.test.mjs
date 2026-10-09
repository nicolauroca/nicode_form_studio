import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { evaluateOperator, evaluateRules, safePattern } from '../../src/com_nicode_form_studio/media/js/rules.js';
import { normalizeDecimal, compareDecimal, stepMatches } from '../../src/com_nicode_form_studio/media/js/decimal.js';

const cases = JSON.parse(readFileSync(new URL('../fixtures/operators.json', import.meta.url), 'utf8'));
for (const [index, fixture] of cases.entries()) {
  test(`shared operator ${index}: ${fixture.operator}`, () => assert.equal(evaluateOperator(fixture.operator, fixture.left, fixture.right, fixture.datatype), fixture.expected));
}
test('exact decimal values and steps', () => {
  assert.equal(normalizeDecimal('-00012.3400'), '-12.34');
  assert.equal(compareDecimal('9007199254740993.01', '9007199254740993.00'), 1);
  assert.equal(stepMatches('0.3', '0.1'), true);
  assert.equal(stepMatches('-0.3', '0.1'), true);
  assert.equal(stepMatches('0.31', '0.1'), false);
  assert.throws(() => normalizeDecimal(1.2));
});
test('unsafe patterns fail closed', () => {
  for (const pattern of ['(a+)+', 'a++', '(?=x)', 'a|b', '\\1']) assert.throws(() => safePattern(pattern));
});
const patterns = JSON.parse(readFileSync(new URL('../fixtures/patterns.json', import.meta.url), 'utf8'));
for (const [index, fixture] of patterns.entries()) {
  test(`shared pattern ${index}`, () => {
    if (!fixture.valid) assert.throws(() => safePattern(fixture.pattern));
    else assert.equal(safePattern(fixture.pattern).test(fixture.value), fixture.matches);
  });
}
test('rule engine hides descendants and drops inactive supplied values', () => {
  const spec = {
    elements: [{uuid:'a',type:'field'}, {uuid:'g',type:'group'}, {uuid:'b',type:'field',parent_uuid:'g'}],
    fields: [{uuid:'a',config:{}}, {uuid:'b',config:{required:true}}],
    rules: [{uuid:'r',when:{field:'a',operator:'equals',value:'hide'},effects:[{target:'g',type:'hide'}]}]
  };
  const result = evaluateRules(spec, {a:'hide',b:'forged'}, {a:'text',b:'text'});
  assert.deepEqual(result.values, {a:'hide'});
  assert.equal(result.states.b.active, false);
  assert.equal(evaluateRules(spec, {a:'show',b:'accepted'}, {a:'text',b:'text'}).states.b.active, true);
});
test('nonconvergent rules stop', () => {
  const spec = {elements:[{uuid:'a',type:'field'}],fields:[{uuid:'a'}],rules:[{uuid:'r',when:{field:'a',operator:'equals',value:'yes'},effects:[{target:'a',type:'set_value',value:'no'}]}]};
  assert.throws(() => evaluateRules(spec, {a:'yes'}, {a:'text'}), /converge/);
});

test('disable excludes supplied values and explicit enable restores required field activity', () => {
  for (const initiallyDisabled of [false,true]) {
    const spec={elements:[{uuid:'trigger',type:'field'},{uuid:'answer',type:'field',parent_uuid:null}],fields:[{uuid:'trigger',type:'text',config:{}},{uuid:'answer',type:'text',config:{required:true,disabled:initiallyDisabled}}],rules:[
      {uuid:'disable',priority:0,when:{field:'trigger',operator:'not_empty'},effects:[{target:'answer',type:'disable'}]},
      {uuid:'enable',priority:1,when:{field:'trigger',operator:'equals',value:'enable'},effects:[{target:'answer',type:'enable'}]}
    ]};
    const types={trigger:'text',answer:'text'};
    const disabled=evaluateRules(spec,{trigger:'disable',answer:'Forged inactive answer'},types);
    assert.equal(disabled.states.answer.active,false); assert.deepEqual(disabled.values,{trigger:'disable'});
    const enabled=evaluateRules(spec,{trigger:'enable',answer:'Restored answer'},types);
    assert.equal(enabled.states.answer.active,true); assert.equal(enabled.states.answer.required,true); assert.equal(enabled.values.answer,'Restored answer');
    assert.equal(evaluateRules(spec,{trigger:'disable',answer:enabled.values.answer},types).states.answer.active,false);
  }
});

test('read-only field prefill uses active source values while editable prefill preserves visitor input', () => {
  const spec = {elements:[{uuid:'a',type:'field'},{uuid:'b',type:'field'},{uuid:'c',type:'field'}], fields:[{uuid:'a',type:'text',config:{}},{uuid:'b',type:'text',config:{readonly:true},prefill:{type:'field',field:'a'}},{uuid:'c',type:'text',config:{},prefill:{type:'field',field:'a'}}],rules:[]};
  assert.deepEqual(evaluateRules(spec,{a:'source',b:'forged',c:'edited'},{a:'text',b:'text',c:'text'}).values,{a:'source',b:'source',c:'edited'});
  spec.fields[0].config.visible=false;
  assert.deepEqual(evaluateRules(spec,{a:'hidden',b:'forged',c:'edited'},{a:'text',b:'text',c:'text'}).values,{b:null,c:'edited'});
});

test('rule priority and lexical UUID ties are independent of authoring order', () => {
  const spec = {elements:[{uuid:'a',type:'field'},{uuid:'b',type:'field'}],fields:[{uuid:'a',config:{}},{uuid:'b',config:{required:true}}],rules:[
    {uuid:'10000000-0000-4000-8000-000000000001',priority:7,when:{field:'a',operator:'equals',value:'yes'},effects:[{target:'b',type:'required'}]},
    {uuid:'10000000-0000-4000-8000-000000000002',priority:7,when:{field:'a',operator:'not_empty'},effects:[{target:'b',type:'optional'}]}
  ]};
  for (const reverse of [false,true]) {
    if (reverse) spec.rules.reverse();
    assert.equal(evaluateRules(spec,{a:'yes',b:null},{a:'text',b:'text'}).states.b.required,false);
  }
  spec.rules.find(rule => rule.effects[0].type === 'required').priority = 8;
  assert.equal(evaluateRules(spec,{a:'yes',b:null},{a:'text',b:'text'}).states.b.required,true);
});

test('readonly option defaults follow dependencies and rules while editable values stay cleared', () => {
  for (const multiple of [false, true]) {
    const spec = {elements:[{uuid:'parent',type:'field'},{uuid:'choice',type:'field'}], fields:[
      {uuid:'parent',type:'text',config:{}},
      {uuid:'choice',type:multiple ? 'multiselect' : 'select',config:{readonly:true},option_defaults:multiple ? 'multiple' : 'single',source:{type:'static',dependencies:['parent'],config:{options:[
        {value:'a',label:'A',default:true,when:{parent:'one'}},
        {value:'b',label:'B',default:true,when:{parent:'two'}},
        {value:'c',label:'C',default:true,when:{parent:'two'}},
        {value:'x',label:'Disabled',default:true,enabled:false}
      ]}}}],rules:[]};
    const run = parent => evaluateRules(spec,{parent,choice:multiple ? [] : null},{parent:'text',choice:'selection'}).values.choice;
    assert.deepEqual(run('one'),multiple ? ['a'] : 'a');
    assert.deepEqual(run('two'),multiple ? ['b','c'] : 'b');
    const filter = {target:'choice',type:'filter_options',value:['c']};
    spec.rules = [{uuid:'rule',when:{field:'parent',operator:'equals',value:'two'},effects:[filter]}];
    assert.deepEqual(run('two'),multiple ? ['c'] : 'c');
    spec.rules[0].effects = [{target:'choice',type:'change_options',value:[{value:'z',label:'Z',default:true}]}];
    assert.deepEqual(run('two'),multiple ? ['z'] : 'z');
    spec.rules[0].effects = [{target:'choice',type:'clear_value'},filter];
    assert.equal(run('two'),null);
    spec.rules[0].effects = [{target:'choice',type:'set_value',value:multiple ? ['b','c'] : 'b'},filter];
    assert.deepEqual(run('two'),multiple ? ['b','c'] : 'b');
    spec.rules = []; delete spec.fields[1].option_defaults;
    assert.deepEqual(run('two'),multiple ? [] : null);
  }
});
