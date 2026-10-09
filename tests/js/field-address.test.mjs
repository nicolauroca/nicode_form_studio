import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {fieldAddress,parseFieldAddress} from '../../src/com_nicode_form_studio/media/js/field-address.js';
test('repeated field addresses retain ancestry, reject ambiguity and enforce depth',()=>{
 const field=randomUUID(), group=randomUUID(), instance=randomUUID();
 const instances=[{group,instance},{group:randomUUID(),instance:randomUUID()}];
 assert.deepEqual(parseFieldAddress(fieldAddress(field,instances)),{field,instances});
 assert.equal(fieldAddress(field),field);
 for(const invalid of ['',field+'/', '/'+field,group+'/'+instance,field+'\n','ABCDEFAB-CDEF-4ABC-8ABC-ABCDEFABCDEF',`${group}/${instance}/${group}/${instance}/${field}`]) assert.throws(()=>parseFieldAddress(invalid));
 assert.throws(()=>fieldAddress(field,[{group:field,instance}]));
 assert.throws(()=>fieldAddress(field,[{group,instance,position:0}]));
 const inherited=Object.assign(Object.create({group,instance}),{a:1,b:2});
 assert.throws(()=>fieldAddress(field,[inherited]));
 const deep=Array.from({length:64},()=>({group:randomUUID(),instance:randomUUID()}));
 assert.equal(fieldAddress(field,deep).length,4772);
 assert.deepEqual(parseFieldAddress(fieldAddress(field,deep)),{field,instances:deep});
 assert.throws(()=>fieldAddress(field,[...deep,{group:randomUUID(),instance:randomUUID()}]));
});
