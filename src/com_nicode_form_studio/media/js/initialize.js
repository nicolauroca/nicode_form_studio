import {createFormInstance} from './form-instance.js';

/** A broken instance must never stop another form on the same Joomla page. */
export function createInitializer(create = createFormInstance) {
  const instances = new WeakMap();
  return function initialize(root) {
    const forms = [...(root.matches?.('[data-nfs-form]') ? [root] : []), ...root.querySelectorAll('[data-nfs-form]')];
    for (const form of forms) {
      if (instances.has(form)) continue;
      // Claim before construction: partial setup must not be repeated on updates.
      instances.set(form, null);
      const unavailable = () => {
        form.dataset.nfsRuntime = 'unavailable';
        const result = form.querySelector('.nfs-result');
        if (result) {
          result.textContent = form.dataset.nfsUnavailable || 'This form is temporarily unavailable.';
          result.setAttribute('role', 'alert');
          result.hidden = false;
        }
        for (const button of form.querySelectorAll('[data-nfs-submit], [data-nfs-next], [data-nfs-previous], [data-nfs-row-change]')) button.disabled = true;
        form.addEventListener('submit', event => { event.preventDefault(); event.stopImmediatePropagation(); }, {capture:true});
      };
      const ready = instance => { instances.set(form, instance); form.dataset.nfsRuntime = 'initialized'; };
      try {
        const result = create(form);
        if (result?.then) {
          form.dataset.nfsRuntime = 'loading';
          const pending = event => { event.preventDefault(); event.stopImmediatePropagation(); };
          form.addEventListener('submit', pending, {capture:true});
          for (const button of form.querySelectorAll('[data-nfs-submit], [data-nfs-next], [data-nfs-previous], [data-nfs-row-change]')) button.disabled = true;
          result.then(ready, unavailable).finally(() => form.removeEventListener('submit', pending, {capture:true}));
        } else ready(result);
      } catch { unavailable(); }
    }
  };
}
