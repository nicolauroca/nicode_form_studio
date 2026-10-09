import test from 'node:test';
import assert from 'node:assert/strict';
import {builderTypeLabel} from '../../src/com_nicode_form_studio/media/js/builder-labels.js';

test('builder provider labels resolve language keys without replacing authored content or identities', () => {
  const metadata = {label_key:'CUSTOM_LABEL',label:'Custom fallback'};
  assert.equal(builderTypeLabel('custom_id',metadata,key => key === 'CUSTOM_LABEL' ? 'Campo especial' : key),'Campo especial');
  assert.equal(builderTypeLabel('custom_id',metadata,key => key),'Custom fallback');
  assert.equal(builderTypeLabel('custom_id',{label_key:'MISSING'},key => key),'custom_id');
  assert.equal(builderTypeLabel('custom_id',{},key => key),'custom_id');
  assert.equal(builderTypeLabel('custom_id',{label_key:[],label:42},key => key),'custom_id');
  assert.deepEqual(metadata,{label_key:'CUSTOM_LABEL',label:'Custom fallback'});
});
