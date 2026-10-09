import {normalizeField, validateField} from '/media/com_nicode_form_studio/js/validation.js';

export const providers = {fields:{'fixture.upper':{
  version:'1.2.0',
  read:controls => controls[0]?.value ?? null,
  normalize:(raw, config) => normalizeField({type:'text', config}, raw, 'text')?.toUpperCase() ?? null,
  validate:(value, config, datatype, state) => validateField({type:'text', config}, value, datatype, state),
  update:(controls, field, state) => { for (const control of controls) control.value = state.value ?? ''; },
}}, operators:{'fixture.suffix':{
  version:'1.2.0',
  evaluate:(left, right) => typeof left === 'string' && typeof right === 'string' && left.endsWith(right),
}}};
