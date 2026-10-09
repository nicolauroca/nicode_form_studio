import {dialog} from './templates.js';
const t = key => Joomla.Text._(`COM_NICODE_FORM_STUDIO_${key.toUpperCase()}`);
const node = (tag, text, attributes = {}) => { const result = document.createElement(tag); if (text !== undefined) result.textContent = text; for (const [key, value] of Object.entries(attributes)) result.setAttribute(key, value); return result; };
const button = (text, action) => { const result = node('button', text, {type:'button', class:'btn btn-secondary'}); result.addEventListener('click', action); return result; };
const label = (host, title, input) => { const wrapper = node('label', title); input.setAttribute('aria-label', title); wrapper.append(input); host.append(wrapper); return input; };
async function api(csrf, task, payload = null, query = {}) {
  const url = new URL('index.php', location.href); url.search = new URLSearchParams({option:'com_nicode_form_studio',task:`datasource.${task}`,format:'json',...query});
  const options = {credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}};
  if (payload !== null) { options.method='POST'; options.body=new URLSearchParams({[csrf]:'1',payload:JSON.stringify(payload)}); }
  const response=await fetch(url,options); let result;
  try { result=await response.json(); } catch { throw new Error(t('session_error')); }
  if (!response.ok || !result.ok) throw new Error(t(typeof result.error === 'string' ? result.error : 'unexpected_error'));
  return result.data;
}
for (const root of document.querySelectorAll('[data-nfs-source-resource]')) {
  const form=root.querySelector('form'), status=root.querySelector('[data-nfs-source-status]'); let dirty=false,busy=false;
  form.addEventListener('input',()=>{dirty=true;status.textContent=t('unsaved');});
  window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});
  form.addEventListener('submit',async event=>{
    event.preventDefault();if(busy || !form.reportValidity())return; const data=new FormData(form); busy=true;form.querySelector('fieldset').disabled=true;
    try {const result=await api(root.dataset.csrf,'configure',{id:Number(root.dataset.id),revision:Number(root.dataset.revision),name:data.get('name'),enabled:data.has('enabled')});root.dataset.revision=result.revision;root.querySelector('[data-nfs-source-revision]').textContent=result.revision;root.querySelector('h1').textContent=data.get('name');dirty=false;status.textContent=t('source_resource_saved');}
    catch(error){status.textContent=error.message;}
    finally{busy=false;form.querySelector('fieldset').disabled=false;}
  });
}
export function mountSourceResources(host, field, fields, providers, root, context, changed, canWrite) {
  const saved = () => { const state=context(); if(state.dirty){root.querySelector('[data-nfs-status]').textContent=t('transfer_save_first');return null;} return state; };
  function catalogue(ui, selected, records, includeDisabled) {
    let before=null;
    const more=button(t('source_resource_load'),()=>ui.run(async()=>{
      const page=await api(root.dataset.csrf,'listing',null,before?{before}:{});
      for(const record of page.rows){if(!includeDisabled && !Number(record.enabled))continue;records.set(String(record.id),record);selected.append(node('option',record.name,{value:record.id}));}
      before=page.next_before;more.hidden=!before;
    }));ui.content.append(more);
  }
  if(canWrite && field.source){
    const capture=button(t('source_resource_capture'),()=>{
      const state=saved();if(!state)return;const ui=dialog(capture,t('source_resource_capture'));
      const name=label(ui.content,t('name'),node('input',undefined,{required:'required',maxlength:'255'}));name.value=field.config?.label||field.name;
      const selected=label(ui.content,t('template_destination'),node('select'));selected.append(node('option',t('source_resource_new'),{value:'0'}));const records=new Map();
      catalogue(ui,selected,records,true);selected.addEventListener('change',()=>{name.value=records.get(selected.value)?.name??field.config?.label??field.name;});
      ui.content.append(node('p',t('source_resource_help')),button(t('source_resource_capture'),()=>{if(!ui.form.reportValidity())return;ui.run(async()=>{
        const record=records.get(selected.value);await api(root.dataset.csrf,'capture',{name:name.value,form_id:state.id,form_revision:state.revision,field_uuid:field.uuid,id:record?.id??0,revision:record?.revision??0});
        ui.status.textContent=t('source_resource_saved');ui.content.append(node('a',t('data_sources'),{href:'index.php?option=com_nicode_form_studio&view=datasources'}));ui.finish();
      });}));name.focus();
    });host.append(capture);
  }
  const apply=button(t('source_resource_apply'),()=>{
    const state=saved();if(!state)return;const ui=dialog(apply,t('source_resource_apply'));
    const selected=label(ui.content,t('data_sources'),node('select',undefined,{required:'required'}));const records=new Map(),bindings=node('div');let record=null;const inputs=new Map();
    catalogue(ui,selected,records,false);ui.content.append(bindings);selected.addEventListener('change',()=>{record=null;inputs.clear();bindings.replaceChildren();});
    ui.content.append(button(t('source_resource_open'),()=>ui.run(async()=>{
      record=null;inputs.clear();bindings.replaceChildren();if(!selected.value)throw new Error(t('invalid_request'));
      record=await api(root.dataset.csrf,'record',null,{id:selected.value});
      for(const [name,parameter] of Object.entries(record.definition.parameters)){
        const input=label(bindings,name,node('select',undefined,{required:'required'}));input.append(node('option',t('none'),{value:''}));
        for(const candidate of fields){const metadata=providers[candidate.type];if(candidate.uuid===field.uuid || metadata?.datatype!==parameter.datatype || Boolean(metadata.multiple)!==parameter.multiple)continue;input.append(node('option',candidate.config?.label||candidate.name,{value:candidate.uuid}));}
        inputs.set(name,input);
      }
    })),button(t('source_resource_apply'),()=>{if(!ui.form.reportValidity())return;ui.run(async()=>{
      if(!record)throw new Error(t('invalid_request'));const result=await api(root.dataset.csrf,'bind',{id:record.id,revision:record.revision,form_id:state.id,field_uuid:field.uuid,bindings:Object.fromEntries([...inputs].map(([name,input])=>[name,input.value]))});
      field.source=result.source;changed();ui.status.textContent=t('source_resource_applied');ui.finish();
    });}));
  });host.append(apply);
}
