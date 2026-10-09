const t = key => Joomla.Text._(`COM_NICODE_FORM_STUDIO_${key.toUpperCase()}`);
const node = (tag, text) => { const element = document.createElement(tag); if (text !== undefined) element.textContent = text; return element; };
export function mountEntitySource(host, field, fields, formId, changed) {
  const existing = ['joomla.categories', 'joomla.articles'].includes(field.source?.type) ? field.source : null;
  const details = node('details'); details.open = Boolean(existing); details.append(node('summary', t('entity_source')));
  const box = node('fieldset'); box.append(node('legend', t('entity_source'))); details.append(box); host.append(details);
  const selected = new Map((existing?.config.category_ids || []).map(id => [Number(id), `#${id}`]));
  const label = (text, input) => { const wrap = node('label', t(text)); wrap.append(input); box.append(wrap); };
  const type = node('select');
  for (const key of ['categories', 'articles']) { const option = node('option', t(`entity_${key}`)); option.value = `joomla.${key}`; type.append(option); }
  type.value = existing?.type || 'joomla.categories'; label('entity_kind', type);
  box.append(node('p', t('entity_scope_help')));
  const selection = node('div'); box.append(selection);
  function renderSelected() {
    selection.replaceChildren();
    for (const [id, title] of selected) {
      const row = node('p', title + ' '), remove = node('button', t('remove')); remove.type = 'button';
      remove.addEventListener('click', () => { selected.delete(id); renderSelected(); }); row.append(remove); selection.append(row);
    }
  }
  renderSelected();
  const search = node('input'); search.type = 'search'; search.maxLength = 100; label('entity_search', search);
  const load = node('button', t('entity_search')); load.type = 'button';
  const more = node('button', t('resource_more')); more.type = 'button'; more.hidden = true;
  const results = node('div'), status = node('p'); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
  const titleOf = category => `${Number(category.id) === 1 ? t('entity_root') : category.title} · ${category.language}${Number(category.published) === 0 ? ` · ${t('entity_unpublished')}` : ''}`;
  if (selected.size) {
    const url = new URL('index.php', location.href); url.search = new URLSearchParams({option: 'com_nicode_form_studio', task: 'source.categories', format: 'json', id: String(formId)});
    for (const id of selected.keys()) url.searchParams.append('ids[]', String(id));
    fetch(url, {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}}).then(async response => {
      const result = await response.json(); if (!response.ok || !result.ok) throw new Error(); if (!box.isConnected) return;
      for (const category of result.data.rows) if (selected.has(Number(category.id))) selected.set(Number(category.id), titleOf(category));
      renderSelected();
    }).catch(() => { if (box.isConnected) status.textContent = t('unexpected_error'); });
  }
  let before = null, busy = false, term = '';
  async function fetchCategories(append) {
    if (busy) return; busy = true; box.disabled = true; status.textContent = '';
    if (!append) { before = null; term = search.value; results.replaceChildren(); }
    try {
      const url = new URL('index.php', location.href); url.search = new URLSearchParams({option: 'com_nicode_form_studio', task: 'source.categories', format: 'json', id: String(formId), search: term, ...(before ? {before: String(before)} : {})});
      const response = await fetch(url, {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}});
      const result = await response.json(); if (!response.ok || !result.ok) throw new Error(); if (!box.isConnected) return;
      for (const category of result.data.rows) {
        const id = Number(category.id), title = titleOf(category);
        if (selected.has(id)) selected.set(id, title);
        const add = node('button', title); add.type = 'button'; add.addEventListener('click', () => { selected.set(id, title); renderSelected(); }); results.append(add);
      }
      before = result.data.next_before; more.hidden = before === null; renderSelected();
      if (!result.data.rows.length) status.textContent = t('entity_no_results');
    } catch { status.textContent = t('unexpected_error'); }
    finally { busy = false; box.disabled = false; }
  }
  load.addEventListener('click', () => fetchCategories(false)); more.addEventListener('click', () => fetchCategories(true));
  search.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); fetchCategories(false); } });
  box.append(load, results, more, status);
  const dependency = node('select'), noDependency = node('option', t('entity_fixed_scope')); noDependency.value = ''; dependency.append(noDependency);
  for (const candidate of fields) if (candidate.uuid !== field.uuid && ['select', 'radio', 'button-group', 'integer', 'hidden', 'text'].includes(candidate.type)) {
    const option = node('option', candidate.config?.label || candidate.name); option.value = candidate.uuid; dependency.append(option);
  }
  dependency.value = existing?.config.category_field || ''; label('entity_dependency', dependency);
  const limit = node('input'); limit.type = 'number'; limit.min = '1'; limit.max = '5000'; limit.step = '1'; limit.required = true; limit.value = String(existing?.config.max_options || 500); label('entity_limit', limit);
  const apply = node('button', t('entity_apply')); apply.type = 'button';
  apply.addEventListener('click', () => {
    if (!selected.size || selected.size > 500 || !limit.checkValidity()) { status.textContent = t('invalid_request'); limit.reportValidity(); return; }
    const config = {category_ids: [...selected.keys()], max_options: Number(limit.value)};
    if (dependency.value) config.category_field = dependency.value;
    field.source = {type: type.value, config, dependencies: dependency.value ? [dependency.value] : [], ttl: 0}; changed();
  });
  box.append(apply);
}
