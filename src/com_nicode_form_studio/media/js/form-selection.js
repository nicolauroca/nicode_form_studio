import {confirmAction} from './confirm-action.js';

export function mountFormSelection(root, api, run, announce) {
  const toolbar = root.querySelector('[data-nfs-form-selection]');
  if (!toolbar) return;
  const t = key => Joomla.Text._(`COM_NICODE_FORM_STUDIO_${key.toUpperCase()}`);
  const boxes = [...root.querySelectorAll('[data-nfs-select-form]')];
  const all = root.querySelector('[data-nfs-select-all]');
  const count = toolbar.querySelector('[data-nfs-selection-count]');
  const operation = toolbar.querySelector('select');
  const apply = toolbar.querySelector('button');
  const selected = () => boxes.filter(box => box.checked && !box.disabled);
  const refresh = () => {
    const items = selected(), enabled = boxes.filter(box => !box.disabled);
    count.textContent = `${t('selection_count')}: ${items.length}`;
    all.checked = items.length > 0 && items.length === enabled.length;
    all.indeterminate = items.length > 0 && items.length < enabled.length;
    all.disabled = enabled.length === 0;
    for (const option of operation.options) option.disabled = option.value === 'trashed' && items.some(box => box.dataset.canTrash !== '1');
    if (operation.selectedOptions[0]?.disabled) operation.value = 'unpublished';
    apply.disabled = items.length === 0;
  };
  for (const box of boxes) box.addEventListener('change', refresh);
  all.addEventListener('change', () => { for (const box of boxes) if (!box.disabled) box.checked = all.checked; refresh(); });
  apply.addEventListener('click', async () => {
    const items = selected();
    if (items.length === 0) return;
    const choice = operation.value;
    const description = `${operation.selectedOptions[0].textContent}\n\n${items.map(box => `${box.dataset.name} (#${box.value})`).join('\n')}\n\n${t('selection_confirm')}`;
    if (!await confirmAction(description, {title: t('selection_title')})) return;
    await run(async () => {
      const result = await api('bulk', {operation: choice, selection: items.map(box => ({id: Number(box.value), revision: Number(box.dataset.revision)}))});
      for (const row of result.rows) {
        const box = boxes.find(candidate => Number(candidate.value) === row.id);
        if (!box) continue;
        box.dataset.revision = String(row.revision); box.checked = false;
        const tr = box.closest('tr');
        tr.querySelector('[data-nfs-row-state]').textContent = t(`state_${row.state}`);
        tr.querySelector('[data-nfs-row-version]').textContent = row.published_revision ?? '—';
        tr.querySelector('[data-nfs-row-modified]').textContent = row.modified_at;
      }
      announce(t('selection_done'));
    }, false);
    refresh();
  });
  refresh();
}
