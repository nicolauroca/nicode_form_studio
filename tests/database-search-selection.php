<?php
declare(strict_types=1);
$selectionKey = random_bytes(32);
$selectionSql = new Nicode\FormStudio\Search\SqlSearchProvider($connection, $registry, new Nicode\FormStudio\Search\CursorCodec($selectionKey));
$selectionRegistry = new Nicode\FormStudio\Registry\SearchProviderRegistry(); $selectionRegistry->register($selectionSql);
$selection = new Nicode\FormStudio\Search\SelectedSearch($selectionRegistry, 'sql', new Nicode\FormStudio\Search\CursorCodec($selectionKey));
$selectionScope = new Nicode\FormStudio\Search\SearchScope([$id => false]);
$selectionRequest = new Nicode\FormStudio\Search\SearchRequest(['form_id' => $id], limit: 2);
$legacyPage = $selectionSql->search($selectionRequest, $selectionScope);
if ($legacyPage->nextCursor === null) { throw new RuntimeException('Search selection fixture lacks multiple pages.'); }
$legacyNext = $selection->search(new Nicode\FormStudio\Search\SearchRequest(['form_id' => $id], limit: 2, cursor: $legacyPage->nextCursor), $selectionScope);
if (array_intersect(array_column($legacyPage->rows, 'id'), array_column($legacyNext->rows, 'id')) !== []) { throw new RuntimeException('Legacy SQL cursor repeated rows through the selector.'); }
$selectedPage = $selection->search($selectionRequest, $selectionScope);
$selectedNext = $selection->search(new Nicode\FormStudio\Search\SearchRequest(['form_id' => $id], limit: 2, cursor: $selectedPage->nextCursor), $selectionScope);
if ($selectedNext->rows !== $legacyNext->rows) { throw new RuntimeException('Wrapped SQL pagination changed the result set.'); }
try { $selection->search(new Nicode\FormStudio\Search\SearchRequest(['form_id' => $id, 'state' => 'spam'], limit: 2, cursor: $legacyPage->nextCursor), $selectionScope); throw new LogicException('Legacy cursor bypassed original SQL query binding.'); } catch (InvalidArgumentException) {}
echo "Configured search boundary: signed provider cursors, native SQL ordering and verified legacy cursor continuation passed.\n";
