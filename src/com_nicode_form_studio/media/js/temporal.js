export const temporalTypes = Object.freeze(['date', 'time', 'datetime', 'month', 'week']);
export function temporalKey(type, value) {
  if (typeof value !== 'string') return null;
  if (type === 'time') {
    const parts = /^([0-9]{2}):([0-9]{2})(?::([0-9]{2}))?$/.exec(value);
    return parts && parts[0] === value && +parts[1] <= 23 && +parts[2] <= 59 && +(parts[3] || 0) <= 59 ? value.length === 5 ? `${value}:00` : value : null;
  }
  if (type === 'datetime') {
    const parts = value.split('T');
    if (parts.length !== 2) return null;
    const date = temporalKey('date', parts[0]), time = temporalKey('time', parts[1]);
    return date !== null && time !== null ? `${date}T${time}` : null;
  }
  const parts = /^([0-9]{4})-(W?)([0-9]{2})(?:-([0-9]{2}))?$/.exec(value);
  if (!parts || parts[0] !== value || +parts[1] < 1) return null;
  const year = +parts[1], number = +parts[3], leap = year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0);
  if (type === 'week') {
    if (parts[2] !== 'W' || parts[4] !== undefined) return null;
    const previous = year - 1, jan1 = (previous + Math.floor(previous / 4) - Math.floor(previous / 100) + Math.floor(previous / 400) + 1) % 7;
    return number >= 1 && number <= (jan1 === 4 || (jan1 === 3 && leap) ? 53 : 52) ? value : null;
  }
  if (parts[2] || number < 1 || number > 12) return null;
  if (type === 'month') return parts[4] === undefined ? value : null;
  if (type !== 'date' || parts[4] === undefined) return null;
  const days = [31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
  return +parts[4] >= 1 && +parts[4] <= days[number - 1] ? value : null;
}
