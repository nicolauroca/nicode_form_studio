<?php
declare(strict_types=1);

$orderForm = $forms->create('Ordering fixture', 'ordering-' . bin2hex(random_bytes(6)), 1);
$orderDraft = $forms->draft($orderForm); $orderField = Nicode\FormStudio\Domain\Uuid::create();
$orderDraft['elements'] = [['uuid' => $orderField, 'type' => 'field']];
$orderDraft['fields'] = [['uuid' => $orderField, 'name' => 'answer', 'type' => 'text', 'config' => []]];
$forms->saveDraft($orderForm, 0, $orderDraft, 1); $orderVersion = $forms->publish($orderForm, 1, 1); $orderSpec = $forms->version($orderForm, $orderVersion);
$orderRows = [];
foreach ([3, 1, 3, 2, 1] as $day) {
    $item = $submissions->persist($orderForm, $orderVersion, $orderSpec, [$orderField => 'order'], hash('sha256', random_bytes(32)));
    $received = '2026-01-0' . $day . ' 12:00:00.000000';
    $connection->execute('UPDATE ' . $connection->table('submissions') . ' SET received_at = :received WHERE id = :id', [':received' => $received, ':id' => $item->id]);
    $orderRows[] = ['id' => $item->id, 'received_at' => $received];
}
$orderHigh = max(array_column($orderRows, 'id')); $orderScope = new Nicode\FormStudio\Search\SearchScope([$orderForm => false]);
foreach (Nicode\FormStudio\Search\SearchRequest::SORTS as $order) {
    $expected = $orderRows;
    usort($expected, static function (array $a, array $b) use ($order): int {
        $left = str_starts_with($order, 'id_') ? [$a['id']] : [$a['received_at'], $a['id']];
        $right = str_starts_with($order, 'id_') ? [$b['id']] : [$b['received_at'], $b['id']];
        return str_ends_with($order, '_asc') ? $left <=> $right : $right <=> $left;
    });
    $orderCursor = null; $actual = []; $pages = 0;
    do {
        if (++$pages > 3) { throw new RuntimeException('Sort cursor failed to terminate.'); }
        $orderedPage = $selection->search(new Nicode\FormStudio\Search\SearchRequest(['form_id' => $orderForm], limit: 2, cursor: $orderCursor, highId: $orderHigh, sort: $order), $orderScope);
        $actual = array_merge($actual, array_map('intval', array_column($orderedPage->rows, 'id'))); $orderCursor = $orderedPage->nextCursor;
        if ($pages === 1) {
            $submissions->persist($orderForm, $orderVersion, $orderSpec, [$orderField => 'outside job window'], hash('sha256', random_bytes(32)));
            $wrongOrder = $order === 'id_asc' ? 'id_desc' : 'id_asc';
            try { $selection->search(new Nicode\FormStudio\Search\SearchRequest(['form_id' => $orderForm], cursor: $orderCursor, highId: $orderHigh, sort: $wrongOrder), $orderScope); throw new RuntimeException('Sort change reused cursor.'); } catch (InvalidArgumentException) {}
        }
    } while ($orderCursor !== null);
    if ($actual !== array_column($expected, 'id')) { throw new RuntimeException('Header order lost ties, scope or high-water boundary.'); }
}
try { new Nicode\FormStudio\Search\SearchRequest(sort: 'id DESC; DROP TABLE anything'); throw new RuntimeException('SQL order text accepted.'); } catch (InvalidArgumentException) {}
echo "Search ordering: four declared header orders, tie breakers, stable keyset coverage, signed sort binding and late-insert exclusion passed.\n";
