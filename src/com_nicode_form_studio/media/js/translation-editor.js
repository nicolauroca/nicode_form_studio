import {revealEditorTarget} from './editor-tabs.js';
import {configurationObject} from './builder-model.js';
import {confirmAction} from './confirm-action.js';

const languagePattern = '[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8}){0,3}';
const validationCodes = ['required', 'type', 'min_length', 'max_length', 'min', 'max', 'step', 'scale', 'precision', 'pattern', 'min_selections', 'max_selections', 'option', 'file_count', 'file_size', 'file_type'];
export const messageCodes = ['success', 'success_heading', 'validation_error', 'session_error', 'anti_spam_rejected', 'rate_limited', 'upload_error', 'captcha_error', 'captcha_unavailable', 'captcha_required', 'persistence_error', 'unexpected_error', 'processing_pending', 'action_blocking_failure', 'action_partial_failure'];

/** Only explicit input creates translations; opening this editor never changes a draft. */
export function mountTranslationEditor(root, {draft, changed, node, button, t}) {
  const panel = root.querySelector('[data-nfs-translations]');
  let locale = Object.keys(draft.translations ?? {})[0] ?? draft.base_language ?? document.documentElement.lang ?? 'en-GB';
  const write = (path, value) => {
    draft.translations = configurationObject(draft.translations);
    let target = draft.translations[locale] = configurationObject(draft.translations[locale]);
    for (const key of path.slice(0, -1)) target = target[key] = configurationObject(target[key]);
    if (value === '') delete target[path.at(-1)]; else target[path.at(-1)] = value;
    changed();
  };
  const read = path => path.reduce((value, key) => value?.[key], draft.translations?.[locale]);
  function text(host, title, path, fallback = '', multiline = false) {
    const label = node('label', title), input = node(multiline ? 'textarea' : 'input', undefined, {'aria-label': title, maxlength: '16000', 'data-nfs-translation-path': JSON.stringify(path)});
    input.value = read(path) ?? ''; input.placeholder = fallback;
    input.addEventListener('input', () => write(path, input.value)); label.append(input); host.append(label);
  }
  function render() {
    panel.replaceChildren();
    panel.append(node('p', t('translation_help')));
    const baseLabel = node('label', t('base_language')), base = node('input', undefined, {'aria-label': t('base_language'), pattern: languagePattern, placeholder: 'en-GB'});
    base.value = draft.base_language ?? ''; base.addEventListener('input', () => { if (base.value === '') delete draft.base_language; else draft.base_language = base.value; changed(); }); baseLabel.append(base); panel.append(baseLabel);
    const descriptionLabel = node('label', t('form_description')), description = node('textarea', undefined, {'aria-label': t('form_description')});
    description.value = draft.metadata?.description ?? ''; description.addEventListener('input', () => { draft.metadata = configurationObject(draft.metadata); draft.metadata.description = description.value; changed(); }); descriptionLabel.append(description); panel.append(descriptionLabel);
    const localeLabel = node('label', t('translation_language')), language = node('input', undefined, {'aria-label': t('translation_language'), pattern: languagePattern, required: 'required', 'data-nfs-translation-locale': ''});
    language.value = locale; localeLabel.append(language); panel.append(localeLabel);
    const saved = Object.keys(draft.translations ?? {});
    if (saved.length) panel.append(node('p', `${t('translation_languages')}: ${saved.join(', ')}`));
    panel.append(button(t('translation_open'), () => { if (!language.reportValidity()) return; locale = saved.find(value => value.toLowerCase() === language.value.toLowerCase()) ?? language.value; render(); }));
    if (saved.includes(locale)) panel.append(button(t('translation_remove'), async () => {
      if (!await confirmAction(`${t('translation_remove_confirm')} (${locale})`)) return;
      delete draft.translations[locale]; changed(); render();
    }));
    const content = node('div', undefined, {class: 'nfs-translation-content'}); content.append(node('h3', locale));
    text(content, t('title'), ['form', 'name'], draft.name);
    text(content, t('form_description'), ['form', 'description'], draft.metadata?.description, true);
    for (const field of draft.fields) {
      const card = node('details'); card.append(node('summary', field.config?.label || field.name));
      for (const property of ['label', 'help', 'description', 'placeholder']) text(card, t(`property_${property}`), ['fields', field.uuid, property], field.config?.[property], property !== 'placeholder');
      const messages = node('details'); messages.append(node('summary', t('translation_validation')));
      for (const code of new Set([...validationCodes, ...Object.keys(field.config?.validation_messages ?? {}), ...Object.keys(draft.translations?.[locale]?.validation?.[field.uuid] ?? {})])) text(messages, validationCodes.includes(code) ? t(`validation_${code}`) : code.replace(/[_.]/g, ' '), ['validation', field.uuid, code], field.config?.validation_messages?.[code]);
      card.append(messages);
      const seen = new Set();
      const ruleOptions = draft.rules.flatMap(rule => rule.effects.filter(effect => effect.type === 'change_options' && effect.target === field.uuid).flatMap(effect => Array.isArray(effect.value) ? effect.value : []));
      for (const option of [...(field.options ?? []), ...(Array.isArray(field.source?.config?.options) ? field.source.config.options : []), ...ruleOptions]) {
        if (!option.uuid || seen.has(option.uuid)) continue; seen.add(option.uuid);
        text(card, `${t('option_value')}: ${option.value}`, ['options', option.uuid, 'label'], option.label);
      }
      content.append(card);
    }
    for (const element of draft.elements.filter(item => item.type !== 'field')) {
      const card = node('details'); card.append(node('summary', element.title || element.text || element.type));
      for (const property of ['title', 'text', ...(element.type === 'step' ? ['description'] : [])]) text(card, t(property === 'description' ? 'property_description' : property), ['elements', element.uuid, property], element[property], true);
      content.append(card);
    }
    for (const action of draft.actions.filter(item => ['email_notification', 'email_autoresponse'].includes(item.type))) {
      const card = node('details'); card.append(node('summary', `${t('actions')}: ${action.type}`));
      for (const property of ['subject', 'body_text', 'body_html']) text(card, t(`action_${property}`), ['actions', action.uuid, property], action.config?.[property], property !== 'subject');
      content.append(card);
    }
    const messages = node('details'); messages.append(node('summary', t('after_submit')));
    for (const code of messageCodes) text(messages, t(`message_${code}`), ['messages', code], draft.post_submit?.messages?.[code], true);
    for (const [index, message] of (draft.post_submit?.conditional_messages ?? []).entries()) {
      if (message.uuid) text(messages, `${t('conditional_message')} ${index + 1}`, ['conditional_messages', message.uuid, 'message'], message.message, true);
    }
    content.append(messages); panel.append(content);
    language.addEventListener('input', () => { for (const input of content.querySelectorAll('input,textarea')) input.disabled = language.value !== locale; });
  }
  panel.closest('[data-nfs-tab-panel]').addEventListener('nfs:panelshown', render);
  render();
  return {
    locale: () => locale,
    reveal: (requestedLocale, path) => {
      if (!Object.hasOwn(draft.translations ?? {}, requestedLocale)) return;
      locale = requestedLocale;
      revealEditorTarget(panel);
      const reveal = () => {
        render();
        const input = [...panel.querySelectorAll('[data-nfs-translation-path]')].find(control => control.dataset.nfsTranslationPath === JSON.stringify(path)) || panel.querySelector('[data-nfs-translation-locale]');
        for (let ancestor = input?.parentElement; ancestor && ancestor !== panel; ancestor = ancestor.parentElement) {
          if (ancestor.tagName === 'DETAILS') ancestor.open = true;
        }
        requestAnimationFrame(() => { if (input?.isConnected) input.focus(); });
      };
      reveal();
    },
  };
}
