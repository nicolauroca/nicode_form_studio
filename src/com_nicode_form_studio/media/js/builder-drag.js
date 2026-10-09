import {containers, dropParent, dropElement} from './builder-model.js';

/** Delegation survives tree rerenders. External drag payloads never identify a source. */
export function mountBuilderDrag(tree, draft, moved) {
  let source = null;
  const clear = () => { for (const node of tree.querySelectorAll('[data-nfs-drop]')) node.removeAttribute('data-nfs-drop'); };
  const destination = event => {
    const row = event.target.closest('[data-nfs-tree-item], [data-nfs-tree-root]');
    if (!source || !row || !tree.contains(row)) return null;
    const target = row.dataset.nfsTreeItem ?? null;
    const item = draft.elements.find(element => element.uuid === target);
    const rect = row.getBoundingClientRect(), fraction = (event.clientY - rect.top) / Math.max(1, rect.height);
    const position = target === null || (containers.includes(item?.type) && fraction >= .25 && fraction <= .75) ? 'inside' : fraction < .5 ? 'before' : 'after';
    try { dropParent(draft, source, target, position); return {row, target, position}; } catch { return null; }
  };
  tree.addEventListener('dragstart', event => {
    const row = event.target.closest('[data-nfs-tree-item]');
    if (!row || !tree.contains(row) || row.disabled) return;
    source = row.dataset.nfsTreeItem;
    event.dataTransfer.effectAllowed = 'move'; event.dataTransfer.setData('text/plain', source);
  });
  const preview = event => {
    clear(); const target = destination(event);
    if (!target) return;
    event.preventDefault(); event.dataTransfer.dropEffect = 'move'; target.row.dataset.nfsDrop = target.position;
  };
  tree.addEventListener('dragenter', preview);
  tree.addEventListener('dragover', preview);
  tree.addEventListener('drop', event => {
    const target = destination(event); clear();
    if (!target) return;
    event.preventDefault(); const uuid = source; source = null;
    dropElement(draft, uuid, target.target, target.position); moved(uuid);
  });
  tree.addEventListener('dragend', () => { source = null; clear(); });
  tree.addEventListener('dragleave', event => { if (!tree.contains(event.relatedTarget)) clear(); });
}
