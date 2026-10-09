<?php
declare(strict_types=1);

test('every core field provider declares a translated palette label in both languages', function (): void {
    $providers = new Nicode\FormStudio\Registry\FieldTypeRegistry();
    Nicode\FormStudio\Field\CoreFieldTypes::register($providers);
    foreach (['en-GB', 'es-ES'] as $locale) {
        $strings = parse_ini_file(dirname(__DIR__, 2).'/src/com_nicode_form_studio/administrator/language/'.$locale.'/com_nicode_form_studio.ini', false, INI_SCANNER_RAW);
        foreach ($providers->metadata() as $metadata) {
            $key = $metadata['label_key'] ?? null;
            same(true, is_string($key) && isset($strings[$key]) && trim($strings[$key]) !== '');
        }
    }
});
