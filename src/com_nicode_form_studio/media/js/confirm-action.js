/** Accessible in-page confirmation, with focus restoration and Escape cancellation. */
export function confirmAction(message) {
  return new Promise(resolve => {
    const previous = document.activeElement;
    const dialog = document.createElement('dialog'); dialog.className = 'nfs-confirm';
    const title = document.createElement('h2'); title.textContent = Joomla.Text._('COM_NICODE_FORM_STUDIO_CONFIRM_ACTION');
    const text = document.createElement('p'); text.textContent = message;
    const id = `nfs-confirm-${crypto.randomUUID()}`; title.id = id; text.id = `${id}-message`;
    dialog.setAttribute('aria-labelledby', id); dialog.setAttribute('aria-describedby', text.id);
    const buttons = document.createElement('div'); buttons.className = 'nfs-admin-filters';
    const cancel = document.createElement('button'); cancel.type = 'button'; cancel.className = 'btn btn-secondary'; cancel.textContent = Joomla.Text._('COM_NICODE_FORM_STUDIO_CANCEL_ACTION');
    const accept = document.createElement('button'); accept.type = 'button'; accept.className = 'btn btn-primary'; accept.textContent = Joomla.Text._('COM_NICODE_FORM_STUDIO_CONFIRM_ACTION');
    let settled = false;
    const finish = value => { if (settled) return; settled = true; dialog.close(); dialog.remove(); previous?.focus(); resolve(value); };
    cancel.addEventListener('click', () => finish(false)); accept.addEventListener('click', () => finish(true));
    dialog.addEventListener('cancel', event => { event.preventDefault(); finish(false); });
    buttons.append(cancel, accept); dialog.append(title, text, buttons); document.body.append(dialog); dialog.showModal(); cancel.focus();
  });
}
