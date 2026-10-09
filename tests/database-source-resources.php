<?php
declare(strict_types=1);

$resourceSources = new Nicode\FormStudio\Registry\DataSourceRegistry(); $resourceSources->register(new Nicode\FormStudio\DataSource\StaticDataSource());
$sourcePackages = new Nicode\FormStudio\Transfer\DefinitionPackage(['fields' => $registry, 'sources' => $resourceSources]);
$sourceRemapper = new Nicode\FormStudio\Domain\DefinitionRemapper(['sources' => $resourceSources]);
$sourceExchange = new Nicode\FormStudio\Application\FormExchange($connection, $forms, $formAdministration, $sourcePackages, new Nicode\FormStudio\Transfer\ImportPreview($sourcePackages, $compiler), new Nicode\FormStudio\Transfer\ImportReviewToken(str_repeat('source-resource-test-', 2), time(...)), $sourceRemapper, $adminAuthorize);
$sourceResources = new Nicode\FormStudio\Application\DataSources($connection, $formAdministration, $sourceExchange, $resourceSources, $registry, $sourceRemapper, $adminAuthorize);
$sourceResourceForm = $formAdministration->create('Source capture', 'source-capture-' . bin2hex(random_bytes(5)), 731);
$sourceResourceDraft = $forms->draft($sourceResourceForm); $sourceInput = Nicode\FormStudio\Domain\Uuid::create(); $sourceTarget = Nicode\FormStudio\Domain\Uuid::create();
$sourceResourceDraft['elements'] = [['uuid' => $sourceInput, 'type' => 'field'], ['uuid' => $sourceTarget, 'type' => 'field']];
$sourceResourceDraft['fields'] = [['uuid' => $sourceInput, 'name' => 'country', 'type' => 'text', 'config' => []], ['uuid' => $sourceTarget, 'name' => 'city', 'type' => 'select', 'config' => [], 'source' => ['type' => 'static', 'dependencies' => [$sourceInput], 'config' => ['api_key' => 'private-resource-credential', 'options' => [['value' => $sourceInput, 'label' => 'Madrid', 'when' => [$sourceInput => 'ES']]]]]]];
$sourceFormRevision = $formAdministration->save($sourceResourceForm, 0, $sourceResourceDraft, 731);
$sourceCaptured = $sourceResources->capture(731, 'Reusable cities', $sourceResourceForm, $sourceFormRevision, $sourceTarget);
$sourceRecord = $sourceResources->read(731, $sourceCaptured['id']);
if (str_contains(json_encode($sourceRecord), 'private-resource-credential') || $sourceRecord['definition']['parameters']['country']['uuid'] !== $sourceInput) { throw new RuntimeException('Resource leaked credentials or lost named parameter.'); }
$sourceInvalidDraft = $sourceResourceDraft; $sourceInvalidDraft['fields'][1]['source']['dependencies'] = [];
$sourceFormRevision = $formAdministration->save($sourceResourceForm, $sourceFormRevision, $sourceInvalidDraft, 731);
try { $sourceResources->capture(731, 'Undeclared dependency', $sourceResourceForm, $sourceFormRevision, $sourceTarget); throw new LogicException('An undeclared source condition was captured.'); } catch (InvalidArgumentException) {}
$sourceFormRevision = $formAdministration->save($sourceResourceForm, $sourceFormRevision, $sourceResourceDraft, 731);
$sourceDestination = $formAdministration->create('Source destination', 'source-destination-' . bin2hex(random_bytes(5)), 731); $sourceDestinationDraft = $forms->draft($sourceDestination);
$destinationInput = Nicode\FormStudio\Domain\Uuid::create(); $destinationTarget = Nicode\FormStudio\Domain\Uuid::create();
$sourceDestinationDraft['elements'] = [['uuid' => $destinationInput, 'type' => 'field'], ['uuid' => $destinationTarget, 'type' => 'field']];
$sourceDestinationDraft['fields'] = [['uuid' => $destinationInput, 'name' => 'country', 'type' => 'text', 'config' => []], ['uuid' => $destinationTarget, 'name' => 'city', 'type' => 'select', 'config' => []]];
$formAdministration->save($sourceDestination, 0, $sourceDestinationDraft, 731);
$boundResource = $sourceResources->bind(731, $sourceCaptured['id'], 1, $sourceDestination, $destinationTarget, ['country' => $destinationInput])['source'];
if ($boundResource['dependencies'] !== [$destinationInput] || $boundResource['config']['options'][0]['when'] !== [$destinationInput => 'ES'] || $boundResource['config']['options'][0]['value'] !== $sourceInput) { throw new RuntimeException('Source binding changed a literal or failed to remap a dependency.'); }
try { $sourceResources->bind(731, $sourceCaptured['id'], 1, $sourceDestination, $destinationTarget, []); throw new LogicException('Missing source binding accepted.'); } catch (InvalidArgumentException) {}
$sourceResources->configure(731, $sourceCaptured['id'], 1, 'Disabled source', false);
try { $sourceResources->bind(731, $sourceCaptured['id'], 1, $sourceDestination, $destinationTarget, ['country' => $destinationInput]); throw new LogicException('Stale source revision accepted.'); } catch (Nicode\FormStudio\Domain\ConcurrentEdit) {}
try { $sourceResources->bind(731, $sourceCaptured['id'], 2, $sourceDestination, $destinationTarget, ['country' => $destinationInput]); throw new LogicException('Disabled source could be applied.'); } catch (DomainException) {}
$sourceResolved = (new Nicode\FormStudio\DataSource\OptionResolver($resourceSources, new Nicode\FormStudio\DataSource\RequestCache()))->resolve($boundResource, [$destinationInput => 'ES']);
if ($sourceResolved[0]['label'] !== 'Madrid' || $boundResource['resource']['revision'] !== 1) { throw new RuntimeException('Resource deactivation changed an existing copy.'); }
$adminDenied = ['formstudio.resources.manage'];
try { $sourceResources->configure(731, $sourceCaptured['id'], 2, 'Denied', true); throw new LogicException('Source write bypassed resource ACL.'); } catch (DomainException) {}
$sourceResources->listing(731); $adminDenied = ['core.edit'];
try { $sourceResources->capture(731, 'Denied', $sourceResourceForm, $sourceFormRevision, $sourceTarget); throw new LogicException('Source capture bypassed form ACL.'); } catch (DomainException) {}
$adminDenied = [];
echo "Reusable sources: portable credential redaction, named typed bindings, reference-only remapping, revision conflicts, disabling, copy isolation and ACL passed.\n";
