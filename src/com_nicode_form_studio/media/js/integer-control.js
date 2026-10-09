// Preserve invalid edits in the draft, so rebuilding an inspector cannot silently
// restore an older value. Native bounds and custom type validity gate saving.
export function bindIntegerControl(input, object, key, {required = false, message, changed}) {
  const typed = value => typeof value === 'number' && Number.isSafeInteger(value);
  if (Object.hasOwn(object, key) && !typed(object[key])) input.setCustomValidity(message);
  input.addEventListener(input.tagName === 'SELECT' ? 'change' : 'input', () => {
    input.setCustomValidity('');
    if (input.value === '' && !input.validity.badInput && !required) delete object[key];
    else {
      const number = Number(input.value);
      if (input.value !== '' && !input.validity.badInput && Number.isSafeInteger(number)) object[key] = number;
      else { object[key] = input.value; input.setCustomValidity(message); }
    }
    changed();
    if (!input.checkValidity()) input.reportValidity();
  });
}
