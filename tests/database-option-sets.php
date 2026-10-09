<?php
declare(strict_types=1);
$resourceAcl = static fn (int $actor, ?int $form, string $permission): bool => $actor === 1 || ($actor === 2 && in_array($permission, ['core.manage', 'formstudio.forms.manage'], true));
$optionResources = new Nicode\FormStudio\Application\OptionSets($connection, $resourceAcl);
$resourceId = $optionResources->create(1, 'Shared provinces');
$resourceRevision = $optionResources->save(1, $resourceId, 0, 'Shared provinces', [
    ['value' => 'MD', 'label' => 'Madrid', 'when' => ['country' => 'ES']],
    ['value' => 'BC', 'label' => 'Barcelona', 'when' => ['country' => 'ES']],
    ['value' => 'PA', 'label' => 'Paris', 'when' => ['country' => 'FR']],
]);
$countryField = Nicode\FormStudio\Domain\Uuid::create();
$pinnedSource = $optionResources->source(2, $resourceId, $resourceRevision, ['country' => $countryField]);
$staticOptions = new Nicode\FormStudio\DataSource\StaticDataSource('option_set');
if ($staticOptions->validateConfiguration($pinnedSource['config'], '/source') !== [] || count($staticOptions->options($pinnedSource['config'], [$countryField => 'ES'], [])) !== 2 || $staticOptions->options($pinnedSource['config'], [$countryField => 'XX'], []) !== []) { throw new RuntimeException('Pinned reusable dependency options failed.'); }
$originalResource = $optionResources->read(1, $resourceId)['snapshot'];
$changedOptions = $originalResource['options']; $changedOptions[0]['label'] = 'Changed Madrid';
$optionResources->save(1, $resourceId, 1, 'Edited provinces', $changedOptions);
$history = $optionResources->history(2, $resourceId);
if (array_map('intval', array_column($history['rows'], 'revision')) !== [2, 1] || count($optionResources->history(2, $resourceId, 2)['rows']) !== 1) { throw new RuntimeException('Resource revision history cursor failed.'); }
if ($optionResources->read(2, $resourceId, 1)['snapshot'] !== $originalResource || $staticOptions->options($pinnedSource['config'], [$countryField => 'ES'], [])[0]['label'] !== 'Madrid') { throw new RuntimeException('Resource editing rewrote immutable history or a published snapshot.'); }
try { $optionResources->save(1, $resourceId, 1, 'Stale edit', $changedOptions); throw new RuntimeException('Stale resource edit accepted.'); } catch (Nicode\FormStudio\Domain\ConcurrentEdit) {}
try { $optionResources->save(2, $resourceId, 2, 'Unauthorized edit', $changedOptions); throw new RuntimeException('Read-only editor changed shared resources.'); } catch (DomainException) {}
try { $optionResources->read(3, $resourceId); throw new RuntimeException('Unauthorized shared resource read.'); } catch (DomainException) {}
try { $optionResources->source(2, $resourceId, 1); throw new RuntimeException('Missing dependency binding accepted.'); } catch (InvalidArgumentException) {}
try { $optionResources->save(1, $resourceId, 2, 'Duplicate values', [['value' => 'x', 'label' => 'One'], ['value' => 'x', 'label' => 'Two']]); throw new RuntimeException('Duplicate option identity accepted.'); } catch (InvalidArgumentException) {}
$connection->execute('UPDATE ' . $connection->table('option_set_versions') . ' SET hash = :hash WHERE option_set_id = :id AND revision = 2', [':hash' => str_repeat('0', 64), ':id' => $resourceId]);
try { $optionResources->read(2, $resourceId, 2); throw new RuntimeException('Corrupt option snapshot accepted.'); } catch (DomainException) {}
if ($optionResources->listing(2)['can_edit']) { throw new RuntimeException('Resource permissions incorrectly reported.'); }
echo "Reusable options: immutable revisions, optimistic edits, resource ACL, dependency binding and snapshot integrity verified.\n";
