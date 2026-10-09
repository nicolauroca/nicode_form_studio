<?php
declare(strict_types=1);

// Evidence is maintained in one catalog; descriptions remain normative SPEC data.
$root = dirname(__DIR__);
$check = ($argv[1] ?? '') === '--check';
if (count($argv) > 2 || (isset($argv[1]) && !$check)) { throw new InvalidArgumentException('Usage: php tools/status.php [--check]'); }
$catalog = json_decode(ltrim(file_get_contents($root . '/docs/requirements-status.json'), "\xEF\xBB\xBF"), true, 512, JSON_THROW_ON_ERROR);
preg_match_all('/\*\*([A-Z][A-Z0-9]+-\d{3})\*\* ([^\r\n]+)/', file_get_contents($root . '/docs/29_REQUIREMENTS_TRACEABILITY.md'), $requirements, PREG_SET_ORDER);
$ids = array_column($requirements, 1);
if ($ids === [] || count(array_unique($ids)) !== count($ids) || !is_array($catalog) || array_diff($ids, array_keys($catalog)) !== [] || array_diff(array_keys($catalog), $ids) !== []) { throw new RuntimeException('Requirement catalog must match every normative ID exactly.'); }
$counts = ['COMPLETE' => 0, 'PARTIAL' => 0, 'PENDING' => 0]; $rows = '';
foreach ($requirements as [, $id, $description]) {
    $entry = $catalog[$id];
    if (!is_array($entry) || array_keys($entry) !== ['status', 'implementation', 'evidence'] || !is_string($entry['status']) || !array_key_exists($entry['status'], $counts)) { throw new RuntimeException('Invalid requirement state: ' . $id); }
    foreach (['implementation', 'evidence'] as $property) {
        if (!is_string($entry[$property]) || trim($entry[$property]) === '' || preg_match('/[\r\n|]/', $entry[$property])) { throw new RuntimeException('Invalid requirement text: ' . $id . '/' . $property); }
    }
    if ($entry['status'] === 'COMPLETE' && ($entry['evidence'] === 'No acceptance evidence' || $entry['implementation'] === 'Not implemented; entire acceptance scope remains.')) { throw new RuntimeException('Completion requires evidence: ' . $id); }
    $counts[$entry['status']]++;
    $rows .= '| ' . $id . ' | ' . $description . ' | ' . $entry['status'] . ' | ' . $entry['implementation'] . ' | ' . $entry['evidence'] . " |\n";
}
$path = $root . '/docs/IMPLEMENTATION_STATUS.md';
$original = file_get_contents($path); $text = str_replace("\r\n", "\n", $original);
$replace = static function(string $pattern, string $replacement) use (&$text): void {
    $text = preg_replace_callback($pattern, static fn() => $replacement, $text, -1, $count);
    if ($count !== 1) { throw new RuntimeException('Status document structure changed; expected one replacement: ' . $pattern); }
};
$replace('/(?:^\| [A-Z][A-Z0-9]+-\d{3} \|[^\n]*\n)+/m', $rows);
$replace('/Complete requirements: \d+\./', 'Complete requirements: ' . $counts['COMPLETE'] . '.');
$replace('/^Total: [^\n]+/m', 'Total: ' . count($ids) . ' requirements; ' . $counts['PARTIAL'] . ' partially implemented; ' . $counts['PENDING'] . ' pending; ' . $counts['COMPLETE'] . ' completed against their acceptance scope. ' . ($counts['COMPLETE'] === count($ids) ? 'Whole-product release acceptance is recorded in REQUIREMENT_ACCEPTANCE.md.' : 'Whole-product end-to-end release acceptance remains open.'));
foreach (['php' => 'php tests/run.php --joomla', 'js' => 'node tools/test-js.mjs'] as $kind => $command) {
    $reportPath = $root . '/build/' . $kind . '-test-results.json';
    if (!is_file($reportPath)) { continue; } // Keep the recorded checkpoint if this checkout has no local run.
    $report = json_decode(file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
    if (!is_int($report['tests'] ?? null) || !is_int($report['failures'] ?? null) || $report['tests'] < 0 || $report['failures'] < 0) { throw new RuntimeException('Malformed test report.'); }
    if ($kind === 'php' && !($report['joomla_integration'] ?? false)) { continue; }
    $replace('/^- `' . preg_quote($command, '/') . '`: [^\n]+/m', '- `' . $command . '`: ' . $report['tests'] . ' tests, ' . $report['failures'] . ' failures.');
}
if ($check && $text !== str_replace("\r\n", "\n", $original)) { fwrite(STDERR, "Requirement status is stale. Run php tools/status.php and review the changes.\n"); exit(1); }
if (!$check) { file_put_contents($path, $text); }
echo count($ids) . ' requirements checked: ' . $counts['COMPLETE'] . ' complete, ' . $counts['PARTIAL'] . ' partial, ' . $counts['PENDING'] . " pending.\n";
