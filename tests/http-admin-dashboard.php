<?php
declare(strict_types=1);

$dashboardFixture = $api('create', ['name' => 'Dashboard <script> literal', 'alias' => 'dashboard-http-' . bin2hex(random_bytes(5))]);
$dashboardRecord = $api('record', query: ['id' => $dashboardFixture['id']]);
$dashboardDefinition = $dashboardRecord['draft'];
$dashboardDefinition['actions'] = [['uuid' => '507e1a89-f273-4271-a9b4-c12bb3f93f5a', 'type' => 'missing-dashboard-provider', 'enabled' => true, 'config' => []]];
$api('save', ['id' => $dashboardFixture['id'], 'revision' => 0, 'draft' => $dashboardDefinition]);
$dashboardPage = $request($base . '?option=com_nicode_form_studio');
$dashboardDom = $dom($dashboardPage['body']);
$assert($dashboardPage['status'] === 200 && $dashboardDom->query('//*[@data-nfs-dashboard]')->length === 1 && str_contains(strtolower($dashboardPage['headers']), 'no-store'), 'Default native dashboard unavailable or cacheable.');
$assert($dashboardDom->query('//*[@data-nfs-metric]')->length === 21, 'Missing dashboard metrics.');
foreach (['forms_draft', 'forms_invalid', 'forms_action', 'forms_provider', 'responses_30'] as $metric) {
    $node = $dashboardDom->query('//*[@data-nfs-metric="' . $metric . '"]')->item(0);
    $assert($node !== null && (int) $node->textContent > 0, 'Native dashboard did not report ' . $metric);
}
$assert(str_contains($dashboardPage['body'], 'Dashboard &lt;script&gt; literal') && !str_contains($dashboardPage['body'], 'Dashboard <script> literal'), 'Dashboard title escaping failed.');
$assert(!str_contains($dashboardPage['body'], 'missing-dashboard-provider') && !str_contains($dashboardPage['body'], 'canonical_payload'), 'Dashboard leaked definition or payload internals.');
$assert($dashboardDom->query('//table[1]/tbody/tr')->length <= 10 && $dashboardDom->query('//table[2]/tbody/tr')->length <= 10, 'Dashboard lists are unbounded.');
file_put_contents($root . '/build/native-dashboard-fixture.json', json_encode(['form_id' => $dashboardFixture['id'], 'timestamp' => gmdate(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Native dashboard: default route, no-store, live compiler/provider alerts, numeric metrics, bounded lists and escaped form names passed.\n";
