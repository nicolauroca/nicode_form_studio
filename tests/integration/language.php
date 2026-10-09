<?php
declare(strict_types=1);

if (!defined('JPATH_BASE')) {
    define('JPATH_BASE', $joomla);
    require $joomla . '/includes/defines.php';
}

test('runtime translations have matching complete English and Spanish key sets', function (): void {
    $base = dirname(__DIR__, 2) . '/src/com_nicode_form_studio/site';
    $english = parse_ini_file($base . '/language/en-GB/com_nicode_form_studio.ini', false, INI_SCANNER_RAW);
    $spanish = parse_ini_file($base . '/language/es-ES/com_nicode_form_studio.ini', false, INI_SCANNER_RAW);
    same(array_keys($english), array_keys($spanish));
    foreach (['en-GB' => 'Submit', 'es-ES' => 'Enviar'] as $tag => $submit) {
        $language = new Joomla\CMS\Language\Language($tag, false);
        same(true, $language->load('com_nicode_form_studio', $base, $tag, true));
        $messages = Nicode\FormStudio\Infrastructure\Joomla\RuntimeMessages::all($language);
        same($submit, $messages['submit']);
        foreach ($messages as $message) { same(false, str_starts_with($message, 'COM_NICODE_')); }
        same(['field' => [$messages['required']]], Nicode\FormStudio\Infrastructure\Joomla\RuntimeMessages::errors(['field' => ['required']], $language));
    }
});

test('administrator translations have matching English and Spanish keys and valid native loading', function (): void {
    $base = dirname(__DIR__, 2) . '/src/com_nicode_form_studio/administrator';
    $english = parse_ini_file($base . '/language/en-GB/com_nicode_form_studio.ini', false, INI_SCANNER_RAW);
    $spanish = parse_ini_file($base . '/language/es-ES/com_nicode_form_studio.ini', false, INI_SCANNER_RAW);
    same(array_keys($english), array_keys($spanish));
    foreach (['en-GB' => 'Save draft', 'es-ES' => 'Guardar borrador'] as $tag => $save) {
        $language = new Joomla\CMS\Language\Language($tag, false);
        same(true, $language->load('com_nicode_form_studio', $base, $tag, true));
        same($save, $language->_('COM_NICODE_FORM_STUDIO_SAVE'));
    }
});

test('native menu and module metadata translations have matching language keys', function (): void {
    $root = dirname(__DIR__, 2) . '/src';
    foreach (['com_nicode_form_studio/administrator/language/%s/com_nicode_form_studio.sys.ini', 'mod_nicode_form_studio/language/%s/mod_nicode_form_studio.ini', 'mod_nicode_form_studio/language/%s/mod_nicode_form_studio.sys.ini'] as $pattern) {
        $english = parse_ini_file($root . '/' . sprintf($pattern, 'en-GB'), false, INI_SCANNER_RAW);
        $spanish = parse_ini_file($root . '/' . sprintf($pattern, 'es-ES'), false, INI_SCANNER_RAW);
        same(array_keys($english), array_keys($spanish));
        foreach (array_merge(array_values($english), array_values($spanish)) as $text) { same(false, trim($text) === ''); }
    }
});
