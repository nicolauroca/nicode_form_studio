import {configurationObject} from './builder-model.js';

/** Edit definition limits without creating row identities or silently repairing values. */
export function mountRepeatLimits(host, element, {control, node, t}) {
  if (element.type !== 'repeatable-group') return;
  element.repeat = configurationObject(element.repeat);
  const limits = element.repeat;
  let minimum, maximum;
  const bounded = value => Number.isInteger(value) && value >= 0 && value <= 10000;
  const updateBounds = () => {
    minimum.max = bounded(limits.max) ? limits.max : 10000;
    maximum.min = bounded(limits.min) ? limits.min : 0;
  };
  minimum = control(host, t('repeat_minimum'), limits, 'min', {type:'integer', minimum:0, maximum:10000}, {required:true, onChange:updateBounds});
  maximum = control(host, t('repeat_maximum'), limits, 'max', {type:'integer', minimum:0, maximum:10000}, {required:true, onChange:updateBounds});
  minimum.required = maximum.required = true;
  updateBounds();
  host.append(node('p', t('repeat_limits_help')));
}
