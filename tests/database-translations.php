<?php
declare(strict_types=1);

$translationForm = $forms->create('Translation history', 'translations-' . bin2hex(random_bytes(5)), 1);
$translationDraft = $forms->draft($translationForm); $translationField = Nicode\FormStudio\Domain\Uuid::create();
$translationDraft['elements'] = [['uuid' => $translationField, 'type' => 'field', 'parent_uuid' => null]];
$translationDraft['fields'] = [['uuid' => $translationField, 'name' => 'consent', 'type' => 'consent', 'config' => ['label' => 'I accept', 'required' => true]]];
$translationDraft['base_language'] = 'en-GB';
$translationDraft['translations'] = ['es-ES' => ['form' => ['name' => 'Consentimiento'], 'fields' => [$translationField => ['label' => 'Acepto las condiciones publicadas']], 'validation' => [$translationField => ['required' => 'Debes aceptar las condiciones']], 'messages' => ['success' => 'Gracias por completar {{form.name}}']]];
$translationRevision = $forms->saveDraft($translationForm, 0, $translationDraft, 1); $translationVersion = $forms->publish($translationForm, $translationRevision, 1);
$translationContext = new Nicode\FormStudio\Submission\RequestContext(0, [1], 'es-ES', 'translation-session', hash('sha256', random_bytes(32)), true);
$translationToken = $attemptTokens->issue($translationForm, $translationVersion, 'translation-session:component');
$translationInvalid = $pipeline->submit(new Nicode\FormStudio\Submission\SubmitRequest($translationForm, $translationVersion, $translationToken, []), $translationContext);
if (($translationInvalid['error_messages'][$translationField]['required'] ?? '') !== 'Debes aceptar las condiciones') { throw new RuntimeException('Published validation translation missing.'); }
$translationResponse = $pipeline->submit(new Nicode\FormStudio\Submission\SubmitRequest($translationForm, $translationVersion, $translationToken, [$translationField => '1']), $translationContext);
if (!($translationResponse['accepted'] ?? false) || $translationResponse['message'] !== 'Gracias por completar Consentimiento') { throw new RuntimeException('Translated pipeline failed: ' . json_encode($translationResponse)); }
$translationRow = $connection->row('SELECT * FROM ' . $connection->table('submissions') . ' WHERE uuid = :uuid', [':uuid' => $translationResponse['reference']]);
$translationPayload = json_decode($translationRow['canonical_payload'], true, 512, JSON_THROW_ON_ERROR);
if ($translationPayload['consents'][$translationField]['text'] !== 'Acepto las condiciones publicadas' || $translationRow['locale'] !== 'es-ES') { throw new RuntimeException('Consent history lost the text shown or response locale.'); }
$consentEvidence = $translationPayload['consents'][$translationField];
if ($consentEvidence['accepted'] !== true || $consentEvidence['form_version_id'] !== $translationVersion || $consentEvidence['received_at'] !== substr($translationRow['received_at'], 0, 19)) { throw new RuntimeException('Consent acceptance/version/timestamp evidence was not captured atomically.'); }
$translationDraft['translations']['es-ES']['fields'][$translationField]['label'] = 'Different later consent';
$translationRevision = $forms->saveDraft($translationForm, (int) $forms->get($translationForm)['draft_revision'], $translationDraft, 1); $forms->publish($translationForm, $translationRevision, 1);
$translationReader = new Nicode\FormStudio\Application\SubmissionReader($submissions, $forms, $connection, static fn (): bool => true);
$translationDetail = $translationReader->read($translationForm, (int) $translationRow['id'], 1);
if ($translationDetail['labels'][$translationField] !== 'Acepto las condiciones publicadas' || $translationDetail['consents'][$translationField]['text'] !== 'Acepto las condiciones publicadas') { throw new RuntimeException('New publication changed translated historical response.'); }
echo "Dynamic translations: published validation and confirmation, canonical version identity, consent text and historical locale isolation verified.\n";
