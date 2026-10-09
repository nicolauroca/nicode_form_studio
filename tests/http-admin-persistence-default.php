<?php
declare(strict_types=1);
$page = $request($base . '?option=com_config&view=component&component=com_nicode_form_studio');
$assert($page['status'] === 200, 'Native storage configuration unavailable.');
$xpath = $dom($page['body']);
$selects = $xpath->query('//select[@id="jform_default_persistence"]');
$assert($selects->length === 1, 'Storage default control missing or duplicated.');
$select = $selects->item(0); $options = [];
foreach ($xpath->query('./option', $select) as $option) {
    $options[$option->getAttribute('value')] = trim($option->textContent);
}
$assert($options === ['full' => 'Store answers and metadata', 'metadata' => 'Store metadata only', 'none' => 'None'], 'Storage default native labels or values differ.');
$assert($select->hasAttribute('required') && $xpath->query('//label[@for="jform_default_persistence"]')->length === 1, 'Required labelled storage default missing.');
$assert(str_contains($page['body'], 'Existing forms and published versions keep their storage policy'), 'Storage default scope explanation missing.');
echo "Native administrator storage default: required labelled control, all three values and scope explanation passed.\n";

$assert($configuration->dbprefix === 'j6_', 'Unexpected isolated fixture table prefix.');
$pdo = new PDO('mysql:host=127.0.0.1;port=13367;dbname=formstudio_joomla;charset=utf8mb4', $configuration->user, $configuration->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$extension = $pdo->query("SELECT extension_id, params FROM j6_extensions WHERE type='component' AND element='com_nicode_form_studio'")->fetch(PDO::FETCH_ASSOC);
$original = json_decode($extension['params'], true, flags: JSON_THROW_ON_ERROR);
$read = static fn (): array => json_decode($pdo->query('SELECT params FROM j6_extensions WHERE extension_id=' . (int) $extension['extension_id'])->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
$pairs = [];
foreach ($xpath->query('//form[@id="component-form"]//input[@name] | //form[@id="component-form"]//select[@name] | //form[@id="component-form"]//textarea[@name]') as $control) {
    if ($control->hasAttribute('disabled')) { continue; }
    $name = $control->getAttribute('name'); $type = $control->getAttribute('type');
    if (str_starts_with($name, 'jform[rules]') || in_array($type, ['button', 'submit', 'file'], true) || (in_array($type, ['checkbox', 'radio'], true) && !$control->hasAttribute('checked'))) { continue; }
    $values = [];
    if ($control->tagName === 'select') {
        foreach ($xpath->query('./option[@selected]', $control) as $option) { $values[] = $option->getAttribute('value'); }
        if ($values === [] && !$control->hasAttribute('multiple')) { $values[] = $xpath->query('./option', $control)->item(0)?->getAttribute('value') ?? ''; }
    } else { $values[] = $control->tagName === 'textarea' ? $control->textContent : $control->getAttribute('value'); }
    foreach ($values as $value) { $pairs[] = rawurlencode($name) . '=' . rawurlencode($value); }
}
parse_str(implode('&', $pairs), $configurationPost);
$configurationPost['id'] = (int) $extension['extension_id']; $configurationPost['component'] = 'com_nicode_form_studio'; $configurationPost['task'] = 'component.apply';
$save = static function (string $mode) use ($request, $base, $configurationPost, $assert): void {
    $post = $configurationPost; $post['jform']['default_persistence'] = $mode;
    $response = $request($base . '?option=com_config', $post);
    $assert(in_array($response['status'], [302, 303], true), 'Native configuration save did not redirect.');
};
try {
    foreach (['metadata', 'none', 'full'] as $mode) {
        $save($mode); $stored = $read();
        $assert(($stored['default_persistence'] ?? null) === $mode, 'Native configuration did not persist the selected mode.');
        foreach ($original as $key => $value) {
            if ($key !== 'default_persistence') { $assert(array_key_exists($key, $stored) && $stored[$key] == $value, 'Storage setting save changed another component option.'); }
        }
        $reloaded = $request($base . '?option=com_config&view=component&component=com_nicode_form_studio');
        $selected = $dom($reloaded['body'])->query('//select[@id="jform_default_persistence"]/option[@selected]');
        $assert($reloaded['status'] === 200 && $selected->length === 1 && $selected->item(0)->getAttribute('value') === $mode, 'Saved default failed native reload.');
        $createdResponse = $request($base . '?option=com_nicode_form_studio&task=form.create', ['payload' => json_encode(['name' => 'Saved default acceptance', 'alias' => 'saved-default-' . bin2hex(random_bytes(6))]), $token => '1']);
        $assert($createdResponse['status'] === 200, 'Form creation after native configuration save failed.');
        $id = json_decode($createdResponse['body'], true, flags: JSON_THROW_ON_ERROR)['data']['id'];
        $statement = $pdo->prepare('SELECT params FROM j6_nicode_form_studio_forms WHERE id=?'); $statement->execute([$id]);
        $draft = json_decode($statement->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
        $assert(($draft['persistence']['mode'] ?? null) === $mode, 'Next HTTP request did not use the saved global default.');
    }
    $beforeInvalid = $read();
    foreach (['invalid', ''] as $invalid) { $save($invalid); $assert($read() === $beforeInvalid, 'Invalid native configuration changed installed parameters.'); }
} finally {
    // Restore through com_config to clear Joomla caches and validation session,
    // then restore the exact original JSON (including absent/defaulted keys).
    try { $save($original['default_persistence'] ?? 'full'); }
    finally {
        $restore = $pdo->prepare('UPDATE j6_extensions SET params=? WHERE extension_id=?'); $restore->execute([$extension['params'], $extension['extension_id']]);
    }
}
$assert($read() === $original, 'Fixture did not restore original component parameters.');
echo "Native configuration save: three modes persisted/reloaded and initialized HTTP-created forms; invalid/empty values rejected and original parameters restored.\n";
