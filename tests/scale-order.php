<?php
declare(strict_types=1);

$root = dirname(__DIR__); define('_JEXEC', 1);
require $root . '/build/joomla-6.0.0/libraries/vendor/autoload.php'; require $root . '/src/lib_nicode_form_studio/autoload.php';
$config = json_decode(ltrim(file_get_contents($root . '/build/database-test.json'), "\xEF\xBB\xBF"), true, 32, JSON_THROW_ON_ERROR);
if ($config['host'] !== '127.0.0.1' || $config['port'] !== 13367 || $config['database'] !== 'formstudio_test') { throw new RuntimeException('Refusing non-test scale server.'); }
$driver = (new Joomla\Database\DatabaseFactory())->getDriver('mysql', ['host' => '127.0.0.1', 'port' => 13367, 'user' => $config['user'], 'password' => $config['password'], 'database' => 'formstudio_scale', 'prefix' => 'scale_', 'charset' => 'utf8mb4']); $driver->connect();
$db = new Nicode\FormStudio\Infrastructure\Database\Connection($driver);
if ((int) $db->row('SELECT COUNT(*) AS total FROM ' . $db->table('submissions'))['total'] !== 1000000) { throw new RuntimeException('Expected existing million-response fixture.'); }
$index = $db->row('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND INDEX_NAME = :index', [':schema' => 'formstudio_scale', ':table' => 'scale_nicode_form_studio_submissions', ':index' => 'nfs_submissions_i8']);
if (!$index) { $db->execute('CREATE INDEX nfs_submissions_i8 ON ' . $db->table('submissions') . ' (form_id, id)'); }
$formRows = $db->rows('SELECT id, alias FROM ' . $db->table('forms') . ' ORDER BY id');
if (count($formRows) !== 100) { throw new RuntimeException('Unexpected scale forms.'); }
$byAlias = array_column($formRows, 'id', 'alias'); $scope = new Nicode\FormStudio\Search\SearchScope(array_fill_keys(array_map('intval', array_column($formRows, 'id')), false));
$fields = new Nicode\FormStudio\Registry\FieldTypeRegistry(); Nicode\FormStudio\Field\CoreFieldTypes::register($fields);
$search = new Nicode\FormStudio\Search\SqlSearchProvider($db, $fields, new Nicode\FormStudio\Search\CursorCodec(random_bytes(32)));
$results = [];
foreach (['global' => [], 'large' => ['form_id' => (int) $byAlias['scale-fixture-1']], 'small' => ['form_id' => (int) $byAlias['scale-fixture-100']]] as $name => $filters) {
    foreach (Nicode\FormStudio\Search\SearchRequest::SORTS as $sort) {
        $request = new Nicode\FormStudio\Search\SearchRequest($filters, limit: 50, sort: $sort); $times = [];
        for ($i = 0; $i < 20; $i++) { $start = hrtime(true); $page = $search->search($request, $scope); $times[] = (hrtime(true) - $start) / 1e6; }
        if (count($page->rows) !== 50 || $page->nextCursor === null) { throw new RuntimeException('Scale sort returned unexpected page.'); }
        sort($times); [$sql, $parameters] = $search->plan($request, $scope);
        $results[$name . '_' . $sort] = ['p95_ms' => $times[18], 'max_ms' => $times[19], 'explain' => $db->rows('EXPLAIN ' . $sql, $parameters)];
        echo $name . ' ' . $sort . ': p95 ' . round($times[18], 2) . " ms\n";
    }
}
file_put_contents($root . '/build/scale-order-results.json', json_encode(['timestamp' => gmdate(DATE_ATOM), 'database' => $driver->getVersion(), 'rows' => 1000000, 'results' => $results, 'limitation' => 'Local synthetic MariaDB benchmark; no production SLO or other-engine timing asserted.'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
