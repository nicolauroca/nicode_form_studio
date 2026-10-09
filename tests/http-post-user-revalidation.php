<?php
declare(strict_types=1);

// Included in the isolated HTTP fixture with its request/cookie session helpers.
$app->loadLanguage($container->get(Joomla\CMS\Language\LanguageFactoryInterface::class)->createLanguage('en-GB', false));
$visitor = new Joomla\CMS\User\User(); $suffix = bin2hex(random_bytes(6)); $password = bin2hex(random_bytes(24));
$data = ['name' => 'POST ACL visitor', 'username' => 'post-acl-' . $suffix, 'email' => 'post-acl-' . $suffix . '@example.test', 'password' => $password, 'password2' => $password, 'groups' => [2], 'block' => 0];
try {
    if (!$visitor->bind($data) || !$visitor->save()) { throw new RuntimeException('POST ACL user creation failed.'); }
} catch (Throwable $error) { if ($visitor->id) { $visitor->delete(); } throw $error; }
$level = new Joomla\CMS\Table\ViewLevel($database, $container->get(Joomla\Event\DispatcherInterface::class));
$level->title = 'POST ACL level ' . $suffix; $level->rules = json_encode([-(int) $visitor->id]);
$aclForm = null; $group = null;
try {
    if (!$level->check() || !$level->store()) { throw new RuntimeException('POST ACL level creation failed.'); }
    $login = $request('/index.php?option=com_users&view=login&tmpl=component');
    $dom = new DOMDocument(); $prior = libxml_use_internal_errors(true);
    try { $dom->loadHTML($login['body']); } finally { libxml_clear_errors(); libxml_use_internal_errors($prior); }
    $xp = new DOMXPath($dom); $loginPost = [];
    foreach ($xp->query('//form[.//input[@name="username"]]//input[@type="hidden"]') as $input) { $loginPost[$input->getAttribute('name')] = $input->getAttribute('value'); }
    $loginPost['username'] = $data['username']; $loginPost['password'] = $password;
    $loginResult = $request('/index.php?option=com_users&task=user.login', $loginPost);
    unset($password, $data, $loginPost);
    if (!in_array($loginResult['status'], [302, 303], true)) { throw new RuntimeException('POST ACL native visitor login failed.'); }
    $aclForm = $forms->create('Authenticated POST revalidation', 'authenticated-post-' . $suffix, (int) $admin->id); $created[] = $aclForm;
    $draft = $forms->edit($aclForm, (int) $admin->id)['draft']; $field = Nicode\FormStudio\Domain\Uuid::create();
    $draft['elements'] = [['uuid' => $field, 'type' => 'field']];
    $draft['fields'] = [['uuid' => $field, 'name' => 'answer', 'type' => 'text', 'config' => ['required' => true]]];
    $draft['actions'] = []; $draft['security']['captcha'] = ['mode' => 'none']; $draft['security']['minimum_seconds'] = 0; $draft['privacy']['store_user'] = true;
    $revision = $forms->save($aclForm, 0, $draft, (int) $admin->id);
    $row = $repository->get($aclForm);
    $settings = ['name' => $row['name'], 'alias' => $row['alias'], 'access' => (int) $level->id, 'language' => '*', 'publish_up' => null, 'publish_down' => null];
    $revision = $forms->settings($aclForm, $revision, $settings, (int) $admin->id); $forms->publish($aclForm, $revision, (int) $admin->id);
    [$destination, $post] = $render($aclForm, 'component'); $post['nfs'] = [$field => 'Authenticated private marker'];
    $level->rules = '[]'; if (!$level->store()) { throw new RuntimeException('POST ACL revocation failed.'); }
    foreach (['json', 'html'] as $format) {
        $response = $request($destination, array_replace($post, ['format' => $format]));
        if ($response['status'] !== 404 || str_contains($response['body'], 'Authenticated private marker')) { throw new RuntimeException('Authenticated stale POST bypassed view-level revocation.'); }
        if ($format === 'json') {
            $result = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
            if (($result['category'] ?? '') !== 'form_unavailable' || ($result['accepted'] ?? null) !== false) { throw new RuntimeException('Authenticated ACL rejection envelope failed.'); }
        }
        if ($db->rows('SELECT id FROM ' . $db->table('submissions') . ' WHERE form_id = :form', [':form' => $aclForm]) !== []) { throw new RuntimeException('Revoked visitor persisted a response.'); }
    }
    $level->rules = json_encode([-(int) $visitor->id]); $level->store();
    [$destination, $fresh] = $render($aclForm, 'component'); $fresh['nfs'] = [$field => 'Authenticated accepted control']; $fresh['format'] = 'json';
    $response = $request($destination, $fresh); $result = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
    $stored = $db->rows('SELECT user_id FROM ' . $db->table('submissions') . ' WHERE form_id = :form', [':form' => $aclForm]);
    if ($response['status'] !== 200 || !($result['accepted'] ?? false) || count($stored) !== 1 || (int) $stored[0]['user_id'] !== (int) $visitor->id) { throw new RuntimeException('Restored authenticated visitor failed or lost trusted identity.'); }
    $group = new Joomla\CMS\Table\Usergroup($database, $container->get(Joomla\Event\DispatcherInterface::class));
    $group->title = 'POST ACL group ' . $suffix; $group->parent_id = 2;
    if (!$group->check() || !$group->store() || !Joomla\CMS\User\UserHelper::addUserToGroup((int) $visitor->id, (int) $group->id)) { throw new RuntimeException('Private POST group setup failed.'); }
    $level->rules = json_encode([(int) $group->id]);
    if (!$level->store()) { throw new RuntimeException('Group-backed view-level setup failed.'); }
    [$destination, $post] = $render($aclForm, 'component'); $post['nfs'] = [$field => 'Removed group private marker'];
    $snapshot = static fn (): array => $db->rows('SELECT * FROM ' . $db->table('submissions') . ' WHERE form_id = :form ORDER BY id', [':form' => $aclForm]);
    $before = $snapshot();
    if (!Joomla\CMS\User\UserHelper::removeUserFromGroup((int) $visitor->id, (int) $group->id)) { throw new RuntimeException('Private POST group removal failed.'); }
    foreach (['json', 'html'] as $format) {
        $response = $request($destination, array_replace($post, ['format' => $format]));
        if ($response['status'] !== 404 || str_contains($response['body'], 'Removed group private marker') || $snapshot() !== $before) { throw new RuntimeException('Existing session retained removed group access.'); }
        if ($format === 'json') {
            $result = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
            if (($result['category'] ?? '') !== 'form_unavailable' || ($result['accepted'] ?? null) !== false) { throw new RuntimeException('Group revocation did not fail with the availability contract.'); }
        } elseif (!str_contains($response['body'], 'This form is unavailable.')) { throw new RuntimeException('Group revocation omitted traditional localized feedback.'); }
    }
    if (!Joomla\CMS\User\UserHelper::addUserToGroup((int) $visitor->id, (int) $group->id)) { throw new RuntimeException('Private POST group restoration failed.'); }
    [$destination, $fresh] = $render($aclForm, 'component'); $fresh['nfs'] = [$field => 'Restored group control']; $fresh['format'] = 'json';
    $response = $request($destination, $fresh); $result = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR); $after = $snapshot();
    if ($response['status'] !== 200 || !($result['accepted'] ?? false) || count($after) !== count($before) + 1 || (int) end($after)['user_id'] !== (int) $visitor->id) { throw new RuntimeException('Restored group access failed or lost trusted identity.'); }
    [$destination, $post] = $render($aclForm, 'component'); $post['nfs'] = [$field => 'Blocked account private marker']; $before = $snapshot();
    $visitor->load((int) $visitor->id); $visitor->block = 1;
    if (!$visitor->save()) { throw new RuntimeException('Fixture account blocking failed.'); }
    foreach (['json', 'html'] as $format) {
        $response = $request($destination, array_replace($post, ['format' => $format]));
        if (!in_array($response['status'], [403, 404], true) || str_contains($response['body'], 'Blocked account private marker') || $snapshot() !== $before) { throw new RuntimeException('Blocked account retained submission access: HTTP ' . $response['status']); }
        if ($format === 'json') {
            $result = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
            if (($result['accepted'] ?? null) !== false || !in_array($result['category'] ?? '', ['session_error', 'form_unavailable'], true)) { throw new RuntimeException('Blocked account rejection envelope failed.'); }
        }
    }
    $blockedPage = $request('/index.php?option=com_nicode_form_studio&view=form&tmpl=component&id=' . $aclForm);
    if ($blockedPage['status'] !== 404 || str_contains($blockedPage['body'], 'data-nfs-form')) { throw new RuntimeException('Blocked account could render a protected form.'); }
    $visitor->block = 0; if (!$visitor->save()) { throw new RuntimeException('Fixture account unblock failed.'); }
    [$destination, $fresh] = $render($aclForm, 'component'); $fresh['nfs'] = [$field => 'Unblocked account control']; $fresh['format'] = 'json';
    $response = $request($destination, $fresh); $result = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR); $after = $snapshot();
    if ($response['status'] !== 200 || !($result['accepted'] ?? false) || count($after) !== count($before) + 1 || (int) end($after)['user_id'] !== (int) $visitor->id) { throw new RuntimeException('Unblocked account did not recover with trusted identity.'); }
    echo "Native authenticated POST: view-level/group revocations and account blocking rejected both transports; blocked render denied, restored grants/account accepted with trusted identity.\n";
} finally {
    if ($aclForm !== null) {
        $row = $repository->get($aclForm); $settings['access'] = 1;
        $forms->settings($aclForm, (int) $row['draft_revision'], $settings, (int) $admin->id);
    }
    if ($level->id) { $level->delete((int) $level->id); }
    $visitor->delete();
    if ($group !== null && $group->id) { $group->delete((int) $group->id); }
}
