<?php
declare(strict_types=1);
$_SERVER['HTTP_HOST'] = '127.0.0.1:13371'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
require __DIR__ . '/joomla-config.php';
$database = $container->get(Joomla\Database\DatabaseInterface::class);
$db = new Nicode\FormStudio\Infrastructure\Database\Connection($database);
$categories = new Nicode\FormStudio\Infrastructure\Joomla\EntitySource($db, 'categories');
$articles = new Nicode\FormStudio\Infrastructure\Joomla\EntitySource($db, 'articles');
$assert = static function (bool $value, string $message): void { if (!$value) { throw new RuntimeException($message); } };
$cacheDirectory = $root . '/build/native-source-cache'; if (!is_dir($cacheDirectory)) { mkdir($cacheDirectory, 0770, true); }
$cacheFactory = $container->get(Joomla\CMS\Cache\CacheControllerFactoryInterface::class);
$cacheOptions = ['storage' => 'file', 'cachebase' => $cacheDirectory, 'caching' => true, 'lifetime' => 1440];
$cacheClock = 100; $clock = static function () use (&$cacheClock): int { return $cacheClock; };
$cacheA = new Nicode\FormStudio\Infrastructure\Joomla\SourceCache($cacheFactory->createCacheController('output', $cacheOptions)->cache, $clock);
$cacheB = new Nicode\FormStudio\Infrastructure\Joomla\SourceCache($cacheFactory->createCacheController('output', $cacheOptions)->cache, $clock);
$cacheKey = 'fixture-' . bin2hex(random_bytes(12));
$cacheA->set($cacheKey, [['value' => 'one', 'label' => 'One']], 2);
$assert(($cacheB->get($cacheKey)[0]['value'] ?? null) === 'one', 'Native Joomla file cache failed across adapters.');
$cacheClock = 102; $assert($cacheB->get($cacheKey) === null, 'Native Joomla source cache ignored exact TTL.');
$cacheA->delete($cacheKey);
$cacheWorker = static function (string $operation, string $key, int $now) use ($root): array {
    $process = proc_open([PHP_BINARY, $root . '/tests/joomla-cache-worker.php', $operation, $key, (string) $now], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, options: ['bypass_shell' => true]);
    if (!is_resource($process)) { throw new RuntimeException('Unable to start cache fixture worker.'); }
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    $workerExit = proc_close($process);
    if ($workerExit !== 0 || $errors !== '') { throw new RuntimeException('Cache fixture worker failed (' . $workerExit . '): ' . $errors . ' output: ' . substr($output, 0, 1500)); }
    return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
};
$cacheClock = 200;
try {
    $cacheA->set($cacheKey, [['value' => 'parent', 'label' => 'Parent 😀']], 2);
    $workerRead = $cacheWorker('get', $cacheKey, 201);
    $assert($workerRead['value'] === [['label' => 'Parent 😀', 'value' => 'parent']] && $workerRead['diagnostics']['reason'] === 'cache_read_unverified', 'Separate process could not read native shared cache.');
    $assert($cacheWorker('delete', $cacheKey, 201)['value'] === null, 'Separate process did not remove shared entry.');
    $cacheFresh = static fn () => new Nicode\FormStudio\Infrastructure\Joomla\SourceCache($cacheFactory->createCacheController('output', $cacheOptions)->cache, $clock);
    $assert($cacheFresh()->get($cacheKey) === null, 'Fresh adapter retained a worker-deleted entry.');
    $cacheWorker('set', $cacheKey, 200);
    $assert($cacheFresh()->get($cacheKey) === [['label' => 'Worker 😀', 'value' => 'worker']], 'Parent failed to read entry written by worker.');
    $assert($cacheWorker('get', $cacheKey, 202)['value'] === null, 'Separate process ignored exact expiry boundary.');
    $assert($cacheFresh()->get($cacheKey) === null, 'Expired shared entry survived worker cleanup.');
} finally { $cacheA->delete($cacheKey); }
echo "Native file cache: cross-process Unicode write/read, deletion, fresh adapter visibility and exact expiry passed.\n";
$database->transactionStart();
try {
    $template = $db->row('SELECT * FROM ' . $db->quote('#__categories') . ' WHERE ' . $db->quote('extension') . " = 'com_content' ORDER BY " . $db->quote('id') . ' LIMIT 1');
    if (!$template) { throw new RuntimeException('Core content category fixture unavailable.'); }
    unset($template['id']);
    $createCategory = static function (string $title, int $parent, int $left, int $right, int $access = 1, string $language = '*', int $published = 1) use ($template, $database): int {
        $row = (object) array_replace($template, ['title' => $title, 'alias' => 'source-' . bin2hex(random_bytes(5)), 'parent_id' => $parent, 'lft' => $left, 'rgt' => $right, 'access' => $access, 'language' => $language, 'published' => $published, 'asset_id' => 0]);
        $database->insertObject('#__categories', $row, 'id'); return (int) $row->id;
    };
    $parent = $createCategory('Source parent', 1, 100000, 100099);
    $visible = $createCategory('Visible category', $parent, 100001, 100002);
    $private = $createCategory('Private category', $parent, 100003, 100004, 9999);
    $createCategory('French category', $parent, 100005, 100006, 1, 'fr-FR');
    $createCategory('Unpublished category', $parent, 100007, 100008, 1, '*', 0);
    $context = ['view_levels' => [1], 'language' => 'en-GB']; $config = ['category_ids' => [$parent]];
    $assert(array_column($categories->options($config, [], $context), 'value') === [(string) $visible], 'Category access, language or publication filtering failed.');
    $assert($categories->options($config, [], []) === [], 'Missing trusted context leaked categories.');
    $uuid = Nicode\FormStudio\Domain\Uuid::create();
    $dependent = $config + ['category_field' => $uuid];
    $assert($categories->options($dependent, [$uuid => '999999'], $context) === [], 'Unapproved parent escaped category scope.');
    $assert(count($categories->options($dependent, [$uuid => (string) $parent], $context)) === 1, 'Declared parent input failed.');
    $db->execute('UPDATE ' . $db->quote('#__categories') . ' SET ' . $db->quote('published') . ' = 0 WHERE ' . $db->quote('id') . ' = :id', [':id' => $parent]);
    $assert($categories->options($config, [], $context) === [], 'Unpublished ancestor leaked children.');
    $db->execute('UPDATE ' . $db->quote('#__categories') . ' SET ' . $db->quote('published') . ' = 1 WHERE ' . $db->quote('id') . ' = :id', [':id' => $parent]);
    $createArticle = static function (string $title, array $overrides = []) use ($database, $visible): int {
        $row = (object) array_replace(['title' => $title, 'alias' => 'source-' . bin2hex(random_bytes(5)), 'introtext' => '', 'fulltext' => '', 'state' => 1, 'catid' => $visible, 'created' => gmdate('Y-m-d H:i:s'), 'created_by' => 0, 'modified' => gmdate('Y-m-d H:i:s'), 'modified_by' => 0, 'publish_up' => null, 'publish_down' => null, 'attribs' => '{}', 'metadata' => '{}', 'metakey' => '', 'metadesc' => '', 'access' => 1, 'language' => '*', 'version' => 1, 'ordering' => 0, 'images' => '{}', 'urls' => '{}'], $overrides);
        $database->insertObject('#__content', $row, 'id'); return (int) $row->id;
    };
    $article = $createArticle('Visible article');
    $createArticle('Private article', ['access' => 9999]);
    $createArticle('French article', ['language' => 'fr-FR']);
    $createArticle('Future article', ['publish_up' => gmdate('Y-m-d H:i:s', time() + 3600)]);
    $createArticle('Expired article', ['publish_down' => gmdate('Y-m-d H:i:s', time() - 3600)]);
    $createArticle('Private category article', ['catid' => $private]);
    $createArticle('Unpublished article', ['state' => 0]);
    $articleConfig = ['category_ids' => [$visible, $private]];
    $assert(array_column($articles->options($articleConfig, [], $context), 'value') === [(string) $article], 'Article access, category, schedule or language filtering failed.');
    $createArticle('Second visible article');
    try { $articles->options($articleConfig + ['max_options' => 1], [], $context); throw new RuntimeException('Option overflow was silently truncated.'); } catch (DomainException) {}
    $assert($articles->validateConfiguration(['category_ids' => [1], 'sql' => 'SELECT *'], '/source') !== [], 'Arbitrary source setting accepted.');
    file_put_contents($root . '/build/joomla-sources-results.json', json_encode(['passed' => true, 'timestamp' => gmdate(DATE_ATOM), 'checks' => ['native persistent cache and exact TTL', 'independent PHP process read/write/delete and expiry', 'approved category scope', 'trusted view levels and language', 'ancestor publication', 'article schedule', 'dependent parent isolation', 'closed overflow']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "Native sources: persistent Joomla cache/TTL, categories, articles, parent scope, access/language, ancestor publication, schedules and closed overflow passed.\n";
} finally { $database->transactionRollback(); }
