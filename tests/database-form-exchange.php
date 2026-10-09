<?php
declare(strict_types=1);
$transferPackages = new Nicode\FormStudio\Transfer\DefinitionPackage(['fields' => $registry]);
$transfer = new Nicode\FormStudio\Application\FormExchange($connection, $forms, $formAdministration, $transferPackages, new Nicode\FormStudio\Transfer\ImportPreview($transferPackages, $compiler), new Nicode\FormStudio\Transfer\ImportReviewToken(str_repeat('test-import-key-', 3), time(...)), new Nicode\FormStudio\Domain\DefinitionRemapper(), $adminAuthorize);
$transferJson = Nicode\FormStudio\Domain\CanonicalJson::encode($transfer->export($adminForm, 0, 731, 'portable'));
$transferChoices = ['policy' => 'duplicate', 'name' => 'Imported draft', 'alias' => 'import-' . bin2hex(random_bytes(6)), 'revision' => 0];
$transferBefore = (int) $connection->row('SELECT COUNT(*) AS n FROM ' . $connection->table('forms'))['n'];
$transferPreview = $transfer->preview($transferJson, $transferChoices, 731);
if (!$transferPreview['can_import_draft'] || (int) $connection->row('SELECT COUNT(*) AS n FROM ' . $connection->table('forms'))['n'] !== $transferBefore) { throw new RuntimeException('Import preview mutated storage or blocked a valid duplicate.'); }
$wrongChoices = $transferChoices; $wrongChoices['name'] = 'Changed after preview';
try { $transfer->import($transferJson, $wrongChoices, 731, $transferPreview['review_token'], true); throw new LogicException('Import accepted altered choices.'); } catch (DomainException) {}
$transferResult = $transfer->import($transferJson, $transferChoices, 731, $transferPreview['review_token'], true);
$transferForm = $forms->get($transferResult['id']);
if ($transferForm['state'] !== 'draft' || $transferForm['published_version_id'] !== null || $transferForm['uuid'] === $forms->get($adminForm)['uuid']) { throw new RuntimeException('Import published or reused duplicate identity.'); }
$transferSource = $forms->get($adminForm); $transferOriginalVersion = $transferSource['published_version_id'];
$updateChoices = ['policy' => 'update', 'name' => 'Updated through import', 'alias' => $transferSource['alias'], 'revision' => (int) $transferSource['draft_revision']];
$updatePreview = $transfer->preview($transferJson, $updateChoices, 731);
$transfer->import($transferJson, $updateChoices, 731, $updatePreview['review_token'], true);
if ($forms->get($adminForm)['published_version_id'] !== $transferOriginalVersion || $forms->get($adminForm)['name'] !== $updateChoices['name']) { throw new RuntimeException('Import update changed activation or failed to save draft.'); }
try { $transfer->import($transferJson, $updateChoices, 731, $updatePreview['review_token'], true); throw new LogicException('Import accepted stale revision.'); } catch (Nicode\FormStudio\Domain\ConcurrentEdit) {}
$adminDenied = ['core.create'];
try { $transfer->preview($transferJson, $transferChoices, 731); throw new LogicException('Import bypassed creation permission.'); } catch (DomainException) {}
$adminDenied = ['core.edit'];
try { $transfer->export($adminForm, 0, 731, 'portable'); throw new LogicException('Definition export bypassed edit permission.'); } catch (DomainException) {}
$adminDenied = [];
$transferResources = new Nicode\FormStudio\Application\OptionSets($connection, $adminAuthorize);
$transferResourceId = $transferResources->create(731, 'Transfer resource');
$transferResources->save(731, $transferResourceId, 0, 'Transfer resource', [['value' => 'ES', 'label' => 'España']]);
$transferSources = new Nicode\FormStudio\Registry\DataSourceRegistry(); $transferSources->register(new Nicode\FormStudio\DataSource\StaticDataSource('option_set')); $transferSources->register(new Nicode\FormStudio\DataSource\StaticDataSource());
$referencePackages = new Nicode\FormStudio\Transfer\DefinitionPackage(['fields' => $registry, 'sources' => $transferSources]);
$referenceTransfer = new Nicode\FormStudio\Application\FormExchange($connection, $forms, $formAdministration, $referencePackages, new Nicode\FormStudio\Transfer\ImportPreview($referencePackages, $compiler), new Nicode\FormStudio\Transfer\ImportReviewToken(str_repeat('test-import-key-', 3), time(...)), new Nicode\FormStudio\Domain\DefinitionRemapper(), $adminAuthorize);
$referenceDefinition = $forms->draft($adminForm); $referenceDefinition['fields'][0]['type'] = 'select';
$referenceDefinition['fields'][0]['source'] = $transferResources->source(731, $transferResourceId, 1);
$referenceJson = Nicode\FormStudio\Domain\CanonicalJson::encode($referencePackages->export($referenceDefinition, 'reference-aware'));
$referenceChoices = $transferChoices; $referenceChoices['alias'] = 'reference-' . bin2hex(random_bytes(6));
$referencePreview = $referenceTransfer->preview($referenceJson, $referenceChoices, 731);
if (!$referencePreview['can_import_draft'] || !$referencePreview['references'][0]['compatible']) { throw new RuntimeException('Compatible pinned option resource rejected.'); }
try { $referenceTransfer->import($referenceJson, $referenceChoices, 731, $referencePreview['review_token'], false); throw new LogicException('Reference import bypassed explicit review.'); } catch (DomainException) {}
$referenceImported = $referenceTransfer->import($referenceJson, $referenceChoices, 731, $referencePreview['review_token'], true);
$referenceImportedSource = $forms->draft($referenceImported['id'])['fields'][0]['source'];
if ($referenceImportedSource['type'] !== 'option_set' || $referenceImportedSource['config']['revision'] !== 1 || $referenceImportedSource['config']['resource_hash'] !== $referenceDefinition['fields'][0]['source']['config']['resource_hash']) { throw new RuntimeException('Reference import changed a pinned resource identity.'); }
$portableResourceJson = Nicode\FormStudio\Domain\CanonicalJson::encode($referencePackages->export($referenceDefinition, 'portable'));
$portableResourceChoices = $transferChoices; $portableResourceChoices['alias'] = 'portable-resource-' . bin2hex(random_bytes(6));
$portableResourcePreview = $referenceTransfer->preview($portableResourceJson, $portableResourceChoices, 731);
if (!$portableResourcePreview['can_import_draft'] || $portableResourcePreview['references'] !== []) { throw new RuntimeException('Portable embedded options retained a destination resource dependency.'); }
$portableResourceImported = $referenceTransfer->import($portableResourceJson, $portableResourceChoices, 731, $portableResourcePreview['review_token'], true);
$portableResourceDraft = $forms->draft($portableResourceImported['id']);
$portableSource = $portableResourceDraft['fields'][0]['source'];
if ($portableSource['type'] !== 'static' || isset($portableSource['config']['resource_uuid']) || $portableSource['config']['options'][0]['value'] !== 'ES' || $portableSource['config']['options'][0]['label'] !== 'España') { throw new RuntimeException('Portable import lost embedded option semantics.'); }
$transferResources->save(731, $transferResourceId, 1, 'Changed after import', [['value' => 'FR', 'label' => 'France']]);
if ($forms->draft($referenceImported['id'])['fields'][0]['source'] !== $referenceImportedSource || $forms->draft($portableResourceImported['id']) !== $portableResourceDraft) { throw new RuntimeException('Resource edits mutated reference or portable imported drafts.'); }
$referenceDefinition['fields'][0]['source']['config']['resource_hash'] = str_repeat('0', 64);
$wrongReference = $referenceTransfer->preview(Nicode\FormStudio\Domain\CanonicalJson::encode($referencePackages->export($referenceDefinition, 'reference-aware')), $referenceChoices, 731);
if ($wrongReference['can_import_draft'] || $wrongReference['conflicts'][0]['code'] !== 'import.resource_incompatible') { throw new RuntimeException('Mismatched resource revision hash was accepted.'); }
$referenceDefinition['uuid'] = Nicode\FormStudio\Domain\Uuid::create();
$collisionChoices = $referenceChoices; $collisionChoices['policy'] = 'conflict';
$collisionPreview = $referenceTransfer->preview(Nicode\FormStudio\Domain\CanonicalJson::encode($referencePackages->export($referenceDefinition)), $collisionChoices, 731);
if ($collisionPreview['can_import_draft'] || !in_array('import.child_uuid_collision', array_column($collisionPreview['conflicts'], 'code'), true)) { throw new RuntimeException('Cross-form child identity collision was not reported.'); }
echo "Definition transfer: side-effect-free preview, signed choices, duplicate identities, draft-only update, preserved activation, stale review rejection and ACL verified.\n";
