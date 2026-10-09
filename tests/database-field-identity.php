<?php
declare(strict_types=1);
$identityForm = $formAdministration->create('Machine name identity', 'identity-' . bin2hex(random_bytes(5)), 731);
$identityEdit = $formAdministration->edit($identityForm, 731);
if ($identityEdit['published_names'] !== []) { throw new RuntimeException('Unpublished fields have a published rename baseline.'); }
$identityDraft = $identityEdit['draft']; $identityField = Nicode\FormStudio\Domain\Uuid::create();
$identityDraft['elements'] = [['uuid' => $identityField, 'type' => 'field', 'parent_uuid' => null]];
$identityDraft['fields'] = [['uuid' => $identityField, 'type' => 'text', 'name' => 'original_name', 'config' => ['label' => 'Original label']]];
$identityRevision = $formAdministration->save($identityForm, 0, $identityDraft, 731);
$identityVersion = $formAdministration->publish($identityForm, $identityRevision, 731);
$identitySpec = $forms->version($identityForm, $identityVersion);
$identityResponse = $submissions->persist($identityForm, $identityVersion, $identitySpec, [$identityField => 'preserved answer'], hash('sha256', random_bytes(32)));
$identityCanonical = $submissions->get($identityForm, $identityResponse->id)['canonical_payload'];
$identityEdit = $formAdministration->edit($identityForm, 731); $identityRevision = (int) $identityEdit['form']['draft_revision'];
$identityDraft['fields'][0]['name'] = 'renamed_answer'; $identityDraft['fields'][0]['config']['label'] = 'Changed label';
$identityRevision = $formAdministration->save($identityForm, $identityRevision, $identityDraft, 731);
if ($formAdministration->edit($identityForm, 731)['published_names'] !== [$identityField => 'original_name']) { throw new RuntimeException('Draft rename replaced published warning baseline.'); }
$identityNewVersion = $formAdministration->publish($identityForm, $identityRevision, 731);
if ($formAdministration->edit($identityForm, 731)['published_names'] !== [$identityField => 'renamed_answer'] || $forms->version($identityForm, $identityVersion)->hash !== $identitySpec->hash || $submissions->get($identityForm, $identityResponse->id)['canonical_payload'] !== $identityCanonical) { throw new RuntimeException('Name publication changed old identity/history or did not advance warning baseline.'); }
if ($forms->version($identityForm, $identityNewVersion)->toArray()['fields'][0]['uuid'] !== $identityField) { throw new RuntimeException('Renaming changed field identity.'); }
$identityDraft['fields'][0]['name'] = str_repeat('a', 256);
try { $formAdministration->save($identityForm, (int) $forms->get($identityForm)['draft_revision'], $identityDraft, 731); throw new LogicException('Oversized machine name reached persistence.'); } catch (InvalidArgumentException) {}
echo "Field identity: published rename baseline, stable UUID, historical response/snapshot immutability and name storage bounds passed.\n";
