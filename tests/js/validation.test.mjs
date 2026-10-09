import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {normalizeField, validateField, validateRelations, nativeConstraintInvalid} from '../../src/com_nicode_form_studio/media/js/validation.js';

test('special email syntax defers only HTML type mismatch to authoritative server validation', () => {
  const control = {disabled:false, checkValidity:()=>false, validity:{typeMismatch:true}};
  for (const value of ['"user"@example.test', 'user@[192.0.2.1]', 'user@[IPv6:2001:db8::1]']) {
    assert.equal(nativeConstraintInvalid({type:'email'},value,control),false);
    for (const key of ['badInput','customError','patternMismatch','rangeOverflow','rangeUnderflow','stepMismatch','tooLong','tooShort','valueMissing']) {
      assert.equal(nativeConstraintInvalid({type:'email'},value,{...control,validity:{typeMismatch:true,[key]:true}}),true);
    }
    assert.equal(nativeConstraintInvalid({type:'email'},value,control,{fields:{email:{}}}),true);
  }
  assert.equal(nativeConstraintInvalid({type:'email'},'a b@example.test',control),true);
  assert.equal(nativeConstraintInvalid({type:'url'},'"user"@example.test',control),true);
  assert.equal(nativeConstraintInvalid({type:'email'},'"user"@example.test',{...control,disabled:true}),false);
});

test('required consent needs explicit acceptance and optional refusal remains false', () => {
  const field = {type:'consent',config:{required:true}}, state = {active:true,required:true};
  for (const raw of [null, '', false, '0']) assert.deepEqual(validateField(field, normalizeField(field, raw, 'boolean'), 'boolean', state), ['required']);
  for (const raw of [[], 'yes', {accepted:true}]) assert.throws(() => normalizeField(field, raw, 'boolean'), TypeError);
  assert.deepEqual(validateField(field, normalizeField(field, '1', 'boolean'), 'boolean', state), []);
  assert.equal(normalizeField(field, '0', 'boolean'), false);
  assert.deepEqual(validateField(field, false, 'boolean', {active:true,required:false}), []);
});

const fixtures = JSON.parse(readFileSync(new URL('../fixtures/normalizers.json', import.meta.url), 'utf8'));
test('shared relational semantics distinguish boolean comparisons from presence counts', () => {
  for (const item of JSON.parse(readFileSync(new URL('../fixtures/relations.json', import.meta.url), 'utf8'))) {
    const spec = {fields:[],validators:[{type:item.type,config:{fields:['a','b'],count:item.count ?? 0}}]};
    assert.deepEqual(validateRelations(spec,item.values,{a:item.datatype,b:item.datatype},{}),item.valid ? {} : {a:[`cross.${item.type}`],b:[`cross.${item.type}`]},JSON.stringify(item));
  }
});
test('selection identities and enabled membership survive normalization without trimming', () => {
  for (const type of ['select','radio','button-group','multiselect','checkbox-group']) {
    const multiple = ['multiselect','checkbox-group'].includes(type), field = {type,config:{}};
    const state = {active:true,required:true,options:[{value:' a ',enabled:true},{value:'disabled',enabled:false}]};
    const value = normalizeField(field, multiple ? [' a ',' a '] : ' a ', 'selection');
    assert.deepEqual(value, multiple ? [' a '] : ' a ');
    assert.deepEqual(validateField(field,value,'selection',state),[]);
    for (const invalid of ['a','disabled','unknown']) assert.deepEqual(validateField(field,multiple ? [invalid] : invalid,'selection',state),['option']);
  }
});
test('multiple selection count limits use distinct normalized identities', () => {
  for (const type of ['multiselect','checkbox-group']) {
    const field = {type,config:{min_selections:2,max_selections:2}}, state = {active:true,required:false,options:['a','b','c'].map(value=>({value}))};
    for (const [raw,errors] of [[[],[]],[['a','a'],['min_selections']],[['a','b'],[]],[['a','b','c'],['max_selections']]]) assert.deepEqual(validateField(field,normalizeField(field,raw,'selection'),'selection',state),errors);
  }
});
test('shared text length limits count Unicode code points rather than UTF16 units', () => {
  for (const item of JSON.parse(readFileSync(new URL('../fixtures/text-length.json', import.meta.url), 'utf8'))) {
    for (const type of ['text','textarea','password','search','telephone']) assert.deepEqual(validateField({type,config:item.config}, item.value, 'text', {active:true,required:false}), item.errors, JSON.stringify(item));
  }
});
test('shared email, color and URL policy checks supplement native control syntax validation', () => {
  for (const item of JSON.parse(readFileSync(new URL('../fixtures/text-types.json', import.meta.url), 'utf8'))) {
    assert.deepEqual(validateField({type:item.type}, item.value, 'text', {active:true,required:false}), item.errors, JSON.stringify(item));
  }
});
test('unquoted email length bounds agree with server policy', () => {
  for (const [value, errors] of [
    ['a'.repeat(64) + '@example.test', []], ['a'.repeat(65) + '@example.test', ['email']],
    ['a@' + 'b'.repeat(63) + '.test', []], ['a@' + 'b'.repeat(64) + '.test', ['email']],
    ['a'.repeat(64) + '@' + ['b'.repeat(63),'c'.repeat(63),'d'.repeat(61)].join('.'), []],
    ['a'.repeat(64) + '@' + ['b'.repeat(63),'c'.repeat(63),'d'.repeat(62)].join('.'), ['email']],
  ]) assert.deepEqual(validateField({type:'email'},value,'text',{active:true,required:false}),errors);
});
test('shared slider defaults and decimal overrides match PHP validation', () => {
  for (const item of JSON.parse(readFileSync(new URL('../fixtures/range.json', import.meta.url), 'utf8'))) {
    assert.deepEqual(validateField({type:'range',config:item.config}, item.value, 'decimal', {active:true,required:false}), item.errors, JSON.stringify(item));
  }
});
test('shared temporal validation covers real calendars and exact inclusive bounds', () => {
  for (const item of JSON.parse(readFileSync(new URL('../fixtures/temporal.json', import.meta.url), 'utf8'))) {
    assert.deepEqual(validateField({type:item.type === 'datetime' ? 'datetime-local' : item.type,config:item.config}, item.value, item.type, {active:true,required:false}), item.errors, JSON.stringify(item));
  }
});
for (const [i, fixture] of fixtures.entries()) {
  test(`shared normalizer ${i}: ${fixture.type}`, () => {
    const run = () => normalizeField({type:fixture.type,config:{}}, fixture.raw, fixture.datatype);
    if (fixture.error) assert.throws(run, TypeError);
    else assert.deepEqual(run(), fixture.expected);
  });
}
test('file presence is empty for an unselected single file and respects configured limits', () => {
  const field = {type:'file',config:{extensions:['txt'],max_bytes:10}}, state = {active:true,required:true};
  assert.equal(normalizeField(field, [], 'file'), null);
  assert.deepEqual(validateField(field, null, 'file', state), ['required']);
  const receipt = normalizeField(field, [{name:'document.txt',size:11,type:'text/plain'}], 'file');
  assert.deepEqual(validateField(field, receipt, 'file', state), ['file_size']);
});
test('cross-field rules count zero as present and skip inactive confirmation counterparts', () => {
  const spec = {fields:[],validators:[{type:'at_least_one',config:{fields:['a','b']}}]};
  assert.deepEqual(validateRelations(spec, {a:0,b:null}, {a:'integer',b:'integer'}, {}), {});
  spec.validators = [{type:'confirmation',config:{fields:['a','b']}}];
  assert.deepEqual(validateRelations(spec, {a:'answer'}, {a:'text',b:'text'}, {}), {});
  assert.deepEqual(validateRelations(spec, {a:'answer',b:'different'}, {a:'text',b:'text'}, {}), {a:['cross.confirmation'],b:['cross.confirmation']});
});
test('index length and decimal bounds fail before submission', () => {
  assert.deepEqual(validateField({type:'text',index_type:'keyword'}, 'a'.repeat(256), 'text', {active:true}), ['index_length']);
  assert.deepEqual(validateField({type:'decimal',index_type:'decimal'}, '1.1234567890123', 'decimal', {active:true}), ['index_precision']);
  assert.deepEqual(validateField({type:'date',index_type:'date'}, '0001-01-01', 'date', {active:true}), ['index_date']);
  assert.deepEqual(validateField({type:'date'}, '0001-01-01', 'date', {active:true}), []);
  assert.deepEqual(validateField({type:'datetime-local',index_type:'datetime'}, '0999-12-31T23:59:59', 'datetime', {active:true}), ['index_date']);
  assert.deepEqual(validateField({type:'datetime-local',index_type:'datetime'}, '1000-01-01T00:00', 'datetime', {active:true}), []);
});
