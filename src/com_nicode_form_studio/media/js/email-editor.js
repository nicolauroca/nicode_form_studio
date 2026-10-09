/** Guided composition using the same validated tokens as saved templates. */
export const emailFields = fields => fields.filter(field => field.type !== 'password' && (field.include_email ?? !field.sensitive));
export const emailFormat = config => config.email_format ?? (config.body_html ? 'html' : 'text');
export function emailInsertion(field, html = false, label = true) {
  const value = field ? `{{field.${field.uuid}.option_label}}` : '{{response.summary}}';
  const content = field && label ? `{{field.${field.uuid}.label}}: ${value}` : value;
  return html ? `<pre style="white-space:pre-wrap;font:inherit">${content}</pre>\n` : `${content}\n`;
}
export function insertEmailText(input, text) {
  const start = input.selectionStart ?? input.value.length, end = input.selectionEnd ?? start;
  input.value = input.value.slice(0, start) + text + input.value.slice(end);
  input.dispatchEvent(new Event('input', {bubbles:true}));
  input.focus(); input.setSelectionRange(start + text.length, start + text.length);
}
export function mountEmailEditor(host, {config, fields, node, button, control, t, changed}) {
  const box = node('fieldset', undefined, {class:'nfs-email-editor'}); box.append(node('legend', t('email_content')));
  const modeLabel = node('label', t('email_format')), mode = node('select', undefined, {'aria-label':t('email_format')});
  for (const value of ['text','html']) mode.append(node('option', t(`email_format_${value}`), {value}));
  mode.value = emailFormat(config); modeLabel.append(mode); box.append(modeLabel);
  const subject = control(box, t('action_subject'), config, 'subject', {type:'string'});
  const tools = node('div', undefined, {class:'nfs-email-tools'});
  const targetLabel = node('label', t('email_insert_into')), target = node('select', undefined, {'aria-label':t('email_insert_into')});
  for (const [value,key] of [['body_text','action_body_text'],['body_html','action_body_html'],['subject','action_subject']]) target.append(node('option',t(key),{value}));
  targetLabel.append(target); tools.append(targetLabel);
  const pickerLabel = node('label', t('email_field')), picker = node('select', undefined, {'aria-label':t('email_field')});
  const eligible = emailFields(fields);
  for (const field of eligible) picker.append(node('option',field.config?.label || field.name,{value:field.uuid}));
  pickerLabel.append(picker); tools.append(pickerLabel);
  const labelWrap = node('label', t('email_with_label')), withLabel = node('input', undefined, {type:'checkbox'}); withLabel.checked=true; labelWrap.append(withLabel); tools.append(labelWrap);
  box.append(tools);
  const textWrap = node('div'), htmlWrap = node('div');
  const text = control(textWrap,t('action_body_text'),config,'body_text',{type:'string'},{multiline:true}); text.rows=10;
  const html = control(htmlWrap,t('action_body_html'),config,'body_html',{type:'string'},{multiline:true}); html.rows=12;
  const inputs={subject,body_text:text,body_html:html};
  for (const [key,input] of Object.entries(inputs)) input.addEventListener('focus',()=>{target.value=key;});
  const addField=button(t('email_insert_field'),()=>{
    const field=eligible.find(item=>item.uuid===picker.value); if(!field)return;
    insertEmailText(inputs[target.value],target.value==='subject' ? `{{field.${field.uuid}.value}}` : emailInsertion(field,target.value==='body_html',withLabel.checked));
  }); addField.disabled=!eligible.length;
  const addAll=button(t('email_insert_all'),()=>{if(target.value!=='subject')insertEmailText(inputs[target.value],emailInsertion(null,target.value==='body_html'));});
  tools.append(addField,addAll);
  const syncTarget=()=>{addAll.disabled=target.value==='subject';}; target.addEventListener('change',syncTarget);
  for(const input of Object.values(inputs))input.addEventListener('focus',syncTarget);
  box.append(node('p',t('email_fields_help')));
  const formatTools=node('div',undefined,{class:'nfs-admin-toolbar'});
  for(const [key,open,close] of [['email_bold','<strong>','</strong>'],['email_italic','<em>','</em>'],['email_paragraph','<p>','</p>'],['email_heading','<h2>','</h2>']])formatTools.append(button(t(key),()=>{
    const selected=html.value.slice(html.selectionStart,html.selectionEnd); insertEmailText(html,open+(selected||t('email_sample_text'))+close);
  }));
  htmlWrap.prepend(formatTools);
  const preview=node('iframe',undefined,{title:t('email_preview'),sandbox:'',class:'nfs-email-preview'}); preview.hidden=true;
  htmlWrap.append(button(t('email_preview'),()=>{
    preview.srcdoc=`<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'"><style>body{font:16px system-ui;padding:16px;color:#222;background:white}pre{white-space:pre-wrap}</style></head><body>${html.value}</body></html>`;
    preview.hidden=!preview.hidden;
  }),node('p',t('email_preview_help')),preview);
  html.addEventListener('input',()=>{preview.hidden=true;});
  const alternative=node('details'); alternative.append(node('summary',t('email_text_alternative')),textWrap);
  box.append(htmlWrap,alternative);
  target.addEventListener('change',()=>{if(target.value==='body_text')alternative.open=true;inputs[target.value].focus();});
  const sync=()=>{
    const isHtml=mode.value==='html'; htmlWrap.hidden=!isHtml; html.required=isHtml;
    alternative.open=!isHtml; alternative.querySelector('summary').hidden=!isHtml;
    target.querySelector('option[value="body_html"]').disabled=!isHtml;
    target.value=isHtml?'body_html':'body_text'; syncTarget();
  };
  mode.addEventListener('change',()=>{
    config.email_format=mode.value;
    if(mode.value==='html' && !html.value && text.value) {
      html.value='<div style="white-space:pre-wrap">'+text.value.replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;')+'</div>';
      html.dispatchEvent(new Event('input',{bubbles:true}));
    }
    changed();sync();
  }); sync();
  host.append(box);
}
