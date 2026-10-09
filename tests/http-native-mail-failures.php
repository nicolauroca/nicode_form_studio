<?php
declare(strict_types=1);

foreach (['blocking' => 'action_blocking_failure', 'non_blocking' => 'action_partial_failure'] as $policy => $category) {
    foreach (['disabled' => 'failed', 'unknown' => 'unknown'] as $failure => $runState) {
        foreach (['json', 'html'] as $format) {
            $form = $forms->create('Native action failure messages', 'native-mail-failure-' . bin2hex(random_bytes(6)), (int) $admin->id); $created[] = $form;
            $draft = $forms->edit($form, (int) $admin->id)['draft']; $field = Nicode\FormStudio\Domain\Uuid::create();
            $draft['elements'] = [['uuid' => $field, 'type' => 'field']];
            $draft['fields'] = [['uuid' => $field, 'name' => 'answer', 'type' => 'text', 'config' => []]];
            $draft['security']['captcha'] = ['mode' => 'none'];
            $draft['post_submit'] = ['behavior' => 'hide', 'messages' => [$category => 'Configured ' . $category . ' {{submission.reference}} <script>action-marker</script>']];
            $draft['actions'] = [['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'type' => 'email_notification', 'failure_policy' => $policy, 'config' => ['to' => ['fixture@example.test'], 'subject' => 'Failure acceptance', 'body_text' => 'Synthetic test only']]];
            $revision = $forms->save($form, 0, $draft, (int) $admin->id); $forms->publish($form, $revision, (int) $admin->id);
            [$status, $html] = $request('/index.php?option=com_nicode_form_studio&view=form&tmpl=component&id=' . $form);
            $document = new DOMDocument(); $prior = libxml_use_internal_errors(true); $document->loadHTML($html); libxml_clear_errors(); libxml_use_internal_errors($prior); $xpath = new DOMXPath($document); $node = $xpath->query('//form[@data-nfs-form]')->item(0);
            if ($status !== 200 || !$node instanceof DOMElement) { throw new RuntimeException('Action-failure form unavailable.'); }
            $post = []; foreach ($xpath->query('.//input[@type="hidden"]', $node) as $input) { $post[$input->getAttribute('name')] = $input->getAttribute('value'); }
            $post['format'] = $format; $post['nfs[' . $field . ']'] = 'Preserved answer';
            $destination = $node->getAttribute('action');
            if (str_starts_with($destination, 'http')) { $destination = parse_url($destination, PHP_URL_PATH) . '?' . parse_url($destination, PHP_URL_QUERY); }
            $before = count($captures());
            [$status, $body] = $request($destination, $post, $failure);
            $rows = $db->rows('SELECT id,uuid,canonical_payload FROM ' . $db->table('submissions') . ' WHERE form_id=:form', [':form' => $form]);
            if ($status !== 200 || count($rows) !== 1 || json_decode($rows[0]['canonical_payload'], true)['values'][$field] !== 'Preserved answer') { throw new RuntimeException('Action failure lost the accepted response.'); }
            $reference = $rows[0]['uuid']; $expected = 'Configured ' . $category . ' ' . $reference . ' <script>action-marker</script>';
            $assertResult = static function (string $body) use ($format, $category, $policy, $expected, $reference): void {
                if (str_contains($body, 'Private test transport')) { throw new RuntimeException('Private transport exception leaked.'); }
                if ($format === 'json') {
                    $result = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                    if ($result['accepted'] !== true || $result['processed'] !== ($policy === 'non_blocking') || $result['category'] !== $category || $result['reference'] !== $reference || $result['message'] !== $expected || $result['behavior'] !== ($policy === 'blocking' ? 'keep' : 'hide')) { throw new RuntimeException('Native action failure response semantics changed.'); }
                } elseif (!str_contains($body, htmlspecialchars($expected, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) || str_contains($body, '<script>action-marker</script>')) { throw new RuntimeException('Native action failure HTML message missing or unsafe.'); }
            };
            $assertResult($body);
            [$retryStatus, $retryBody] = $request($destination, $post, $failure); $assertResult($retryBody);
            $runs = $db->rows('SELECT state FROM ' . $db->table('action_runs') . ' WHERE submission_id=:id', [':id' => (int) $rows[0]['id']]);
            if ($retryStatus !== 200 || count($captures()) !== $before + 1 || count($runs) !== 1 || $runs[0]['state'] !== $runState || count($db->rows('SELECT id FROM ' . $db->table('submissions') . ' WHERE form_id=:form', [':form' => $form])) !== 1) { throw new RuntimeException('Native failure replay repeated delivery or changed durable result.'); }
        }
    }
}
echo "Native action failure messages: blocking/partial and definite/unknown outcomes in JSON/HTML, preserved answer/reference, safe messages and replay without second transport call passed.\n";
