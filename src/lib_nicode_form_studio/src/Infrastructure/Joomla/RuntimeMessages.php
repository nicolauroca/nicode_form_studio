<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Infrastructure\Joomla;

use Joomla\CMS\Language\Language;

final class RuntimeMessages
{
    private const KEYS = ['previous', 'next', 'submit', 'leave_empty', 'validation_error', 'form_unavailable', 'session_error', 'anti_spam_rejected', 'rate_limited', 'upload_error', 'captcha_error', 'captcha_unavailable', 'captcha_required', 'persistence_error', 'unexpected_error', 'submitting', 'success', 'success_heading', 'timeout', 'required', 'type', 'min_length', 'max_length', 'min', 'max', 'step', 'scale', 'precision', 'pattern', 'min_selections', 'min_instances', 'max_selections', 'file_count', 'file_size', 'file_type', 'option', 'index_length', 'index_precision', 'index_date'];

    public static function all(Language $language): array
    {
        $messages = [];
        foreach (self::KEYS as $key) { $messages[$key] = $language->_('COM_NICODE_FORM_STUDIO_' . strtoupper($key)); }
        foreach (['email', 'url', 'color', 'date', 'time', 'datetime', 'month', 'week'] as $key) { $messages[$key] = $language->_('COM_NICODE_FORM_STUDIO_' . strtoupper($key)); }
        foreach (['add_row', 'remove_row', 'repeat_group', 'repeat_row', 'rows_updated', 'rows_updating', 'rows_failed'] as $key) { $messages[$key] = $language->_('COM_NICODE_FORM_STUDIO_' . strtoupper($key)); }
        foreach (['processing_pending', 'action_blocking_failure', 'action_partial_failure', 'options_retry', 'options_loading', 'options_unavailable', 'options_preview'] as $key) { $messages[$key] = $language->_('COM_NICODE_FORM_STUDIO_' . strtoupper($key)); }
        foreach (\Nicode\FormStudio\Validation\RelationalValidator::IDS as $key) { $messages['cross.' . $key] = $language->_('COM_NICODE_FORM_STUDIO_CROSS_' . strtoupper($key)); }
        return $messages;
    }

    public static function errors(array $errors, Language $language, array $overrides = []): array
    {
        $messages = self::all($language); $result = [];
        foreach ($errors as $uuid => $codes) { $result[$uuid] = array_map(static fn (string $code): string => $overrides[$uuid][$code] ?? $messages[$code] ?? $messages['validation_error'], $codes); }
        return $result;
    }
}
