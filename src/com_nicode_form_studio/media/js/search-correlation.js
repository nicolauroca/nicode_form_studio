/** Keep an existing constraint visible even if its field/provider no longer supports it. */
export function correlationControl(input, initial, unavailable) {
  input.type = 'text'; input.maxLength = 32; input.pattern = '[a-z][a-z0-9_]{0,31}';
  input.value = initial ?? '';
  let supported = false;
  const validate = () => input.setCustomValidity(input.value !== '' && !supported ? unavailable : '');
  input.addEventListener('input', validate);
  return {
    update(available) { supported = available === true; validate(); return supported || input.value !== ''; },
    apply(filter) { if (input.value !== '') filter.same_instance = input.value; return filter; },
  };
}
