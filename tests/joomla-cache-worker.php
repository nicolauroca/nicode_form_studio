<?php
declare(strict_types=1);

// Synthetic cross-process cache fixture, restricted to the isolated Joomla site.
$operation = $argv[1] ?? ''; $key = $argv[2] ?? ''; $now = $argv[3] ?? '';
if (!in_array($operation, ['get', 'set', 'delete'], true) || !preg_match('/^fixture-[a-f0-9]{24}$/D', $key) || !ctype_digit($now)) { throw new InvalidArgumentException('Invalid cache worker fixture.'); }
ob_start();
$_SERVER['HTTP_HOST'] = '127.0.0.1:13371'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
try { require __DIR__ . '/joomla-config.php'; } finally { ob_end_clean(); }
$operation = $argv[1]; $key = $argv[2]; $now = $argv[3];
$factory = $container->get(Joomla\CMS\Cache\CacheControllerFactoryInterface::class);
$cache = new Nicode\FormStudio\Infrastructure\Joomla\SourceCache($factory->createCacheController('output', ['storage' => 'file', 'cachebase' => $root . '/build/native-source-cache', 'caching' => true, 'lifetime' => 1440])->cache, static fn (): int => (int) $now);
if ($operation === 'set') { $cache->set($key, [['value' => 'worker', 'label' => 'Worker 😀']], 2); }
elseif ($operation === 'delete') { $cache->delete($key); }
echo json_encode(['value' => $cache->get($key), 'diagnostics' => $cache->diagnostics()], JSON_THROW_ON_ERROR);
