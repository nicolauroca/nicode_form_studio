/** Keep inline feedback and control descriptions in sync with the error summary. */
export function activeFieldErrors(errors, states) {
  return Object.fromEntries(Object.entries(errors).filter(([uuid]) => states[uuid]?.active !== false));
}

export function updateFieldError(instance, uuid, node, controls, messages) {
  if (!node) return;
  const text = Array.isArray(messages) ? messages.join(' ') : messages == null ? '' : String(messages);
  let error = node.querySelector(`[data-nfs-error="${uuid.replace(/["\\]/g, '\\$&')}"]`);
  if (!error && text) {
    error = node.ownerDocument.createElement('div');
    error.className = 'nfs-error'; error.id = `${instance}-${uuid}-error`;
    error.setAttribute('data-nfs-error', uuid); node.append(error);
  }
  if (error) { error.textContent = text; error.hidden = !text; }
  for (const control of controls) {
    const descriptions = new Set((control.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
    if (error) { descriptions.delete(error.id); if (text) descriptions.add(error.id); }
    if (descriptions.size) control.setAttribute('aria-describedby', [...descriptions].join(' '));
    else control.removeAttribute('aria-describedby');
    if (text) control.setAttribute('aria-invalid', 'true'); else control.removeAttribute('aria-invalid');
  }
}
