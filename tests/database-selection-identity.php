<?php
declare(strict_types=1);
$identityForm = $forms->create('Selection identity search', 'selection-identity-' . bin2hex(random_bytes(5)), 1);
$identityDraft = $forms->draft($identityForm); $identityField = Nicode\FormStudio\Domain\Uuid::create();
$identityOptions = ['a', 'a ', 'A', 'á', "a\u{0301}", ' a', 'a  '];
$identityResource = $optionResources->create(1, 'Exact option identities');
$optionResources->save(1, $identityResource, 0, 'Exact option identities', array_map(static fn ($value) => ['value' => $value, 'label' => $value], $identityOptions));
$storedOptionValues = array_column($optionResources->read(1, $identityResource)['snapshot']['options'], 'value');
if ($storedOptionValues !== $identityOptions) { throw new RuntimeException('OptionSet storage conflated literal identities.'); }
if (!$postgres) {
    $identityQueryCount = $driver->getCount();
    Nicode\FormStudio\Infrastructure\Database\IdentitySchema::upgrade($driver);
    if ($driver->getCount() - $identityQueryCount !== 3) { throw new RuntimeException('Completed identity migration repeated DDL instead of only inspecting three columns.'); }
}
$identityDraft['elements'] = [['uuid' => $identityField, 'type' => 'field', 'parent_uuid' => null]];
$identityDraft['fields'] = [['uuid' => $identityField, 'name' => 'identity', 'type' => 'select', 'index' => true, 'config' => [], 'options' => array_map(static fn ($value) => ['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'value' => $value, 'label' => 'Option ' . $value], $identityOptions)]];
$identityRevision = $forms->saveDraft($identityForm, 0, $identityDraft, 1); $identityVersion = $forms->publish($identityForm, $identityRevision, 1); $identitySpec = $forms->version($identityForm, $identityVersion); $identityRows = [];
foreach ($identityOptions as $value) { $identityRows[$value] = $submissions->persist($identityForm, $identityVersion, $identitySpec, [$identityField => $value], hash('sha256', random_bytes(32)))->id; }
$identityScope = new Nicode\FormStudio\Search\SearchScope([$identityForm => false]);
foreach ($identityRows as $value => $expectedId) {
    foreach (['equals', 'not_equals'] as $operator) {
        $page = $search->search(new Nicode\FormStudio\Search\SearchRequest(['form_id' => $identityForm], [['field' => $identityField, 'operator' => $operator, 'value' => $value]]), $identityScope, $identitySpec);
        $actualIds = array_map('intval', array_column($page->rows, 'id')); sort($actualIds);
        $expectedIds = $operator === 'equals' ? [$expectedId] : array_values(array_diff(array_values($identityRows), [$expectedId])); sort($expectedIds);
        if ($actualIds !== $expectedIds) { throw new RuntimeException('Selection search conflated literal identities for ' . $operator . ' and ' . json_encode($value)); }
    }
}
$identityTextForm = $forms->create('Exact long text', 'exact-text-' . bin2hex(random_bytes(5)), 1);
$identityTextDraft = $forms->draft($identityTextForm); $identityTextDraft['elements'] = $identityDraft['elements'];
$identityTextDraft['fields'] = [['uuid' => $identityField, 'name' => 'answer', 'type' => 'textarea', 'index' => true, 'config' => ['trim' => false]]];
$identityTextRevision = $forms->saveDraft($identityTextForm, 0, $identityTextDraft, 1); $identityTextVersion = $forms->publish($identityTextForm, $identityTextRevision, 1); $identityTextSpec = $forms->version($identityTextForm, $identityTextVersion);
$identityTextRows = [];
foreach (['a', 'a '] as $value) { $identityTextRows[$value] = $submissions->persist($identityTextForm, $identityTextVersion, $identityTextSpec, [$identityField => $value], hash('sha256', random_bytes(32)))->id; }
foreach ($identityTextRows as $value => $expectedId) {
    $page = $search->search(new Nicode\FormStudio\Search\SearchRequest(['form_id' => $identityTextForm], [['field' => $identityField, 'operator' => 'equals', 'value' => $value]]), new Nicode\FormStudio\Search\SearchScope([$identityTextForm => false]), $identityTextSpec);
    if (array_map('intval', array_column($page->rows, 'id')) !== [$expectedId]) { throw new RuntimeException('Long text search ignored trailing spaces.'); }
}
echo "Selection identity search: exact case, accents, Unicode composition, leading/trailing spaces, OptionSet uniqueness, idempotent migration and long-text equality passed.\n";
