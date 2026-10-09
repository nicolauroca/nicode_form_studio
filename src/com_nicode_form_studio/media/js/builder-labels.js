export function builderTypeLabel(type, metadata, translate) {
  const key = metadata?.label_key;
  if (typeof key === 'string' && key !== '') {
    const label = translate(key);
    if (typeof label === 'string' && label !== '' && label !== key) return label;
  }
  return typeof metadata?.label === 'string' && metadata.label !== '' ? metadata.label : type;
}
