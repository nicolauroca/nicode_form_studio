export function normalizeDecimal(input) {
  if (typeof input !== 'string' && !(typeof input === 'number' && Number.isSafeInteger(input))) {
    throw new TypeError('Expected an exact decimal string.');
  }
  const match = String(input).replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '').match(/^([+-]?)([0-9]+)(?:\.([0-9]+))?$/);
  if (!match) throw new TypeError('Invalid decimal.');
  const integer = match[2].replace(/^0+/, '') || '0';
  const fraction = (match[3] || '').replace(/0+$/, '');
  return (match[1] === '-' && (integer !== '0' || fraction) ? '-' : '') + integer + (fraction ? `.${fraction}` : '');
}

export function compareDecimal(left, right) {
  const a = normalizeDecimal(left), b = normalizeDecimal(right);
  const an = a.startsWith('-'), bn = b.startsWith('-');
  if (an !== bn) return an ? -1 : 1;
  const [ai, af = ''] = a.replace(/^-/, '').split('.');
  const [bi, bf = ''] = b.replace(/^-/, '').split('.');
  const width = Math.max(af.length, bf.length);
  const cmp = (x, y) => x < y ? -1 : x > y ? 1 : 0;
  const result = cmp(ai.length, bi.length) || cmp(ai, bi) || cmp(af.padEnd(width, '0'), bf.padEnd(width, '0'));
  return an ? -result : result;
}

export function stepMatches(value, step, base = '0') {
  const strings = [value, step, base].map(normalizeDecimal);
  if (compareDecimal(strings[1], '0') <= 0) throw new TypeError('Step must be positive.');
  const scale = Math.max(...strings.map(s => (s.split('.')[1] || '').length));
  const [v, s, b] = strings.map(text => {
    const [integer, fraction = ''] = text.split('.');
    return BigInt(integer + fraction.padEnd(scale, '0'));
  });
  return (v - b) % s === 0n;
}
