<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/lib_nicode_form_studio/autoload.php';
if (!is_dir(__DIR__ . '/artifacts')) { mkdir(__DIR__ . '/artifacts', 0770, true); }

$tests = [];
function test(string $name, callable $body): void { global $tests; $tests[$name] = $body; }
function same(mixed $expected, mixed $actual): void {
    if ($expected !== $actual) { throw new RuntimeException('Expected ' . var_export($expected, true) . '; got ' . var_export($actual, true)); }
}
function raises(string $class, callable $body): void {
    try { $body(); } catch (Throwable $error) {
        if ($error instanceof $class) { return; }
        throw $error;
    }
    throw new RuntimeException('Expected exception ' . $class);
}
foreach (glob(__DIR__ . '/unit/*.php') as $file) { require $file; }
if (in_array('--joomla', $argv, true)) {
    $joomla = getenv('JOOMLA_TEST_ROOT') ?: dirname(__DIR__) . '/build/joomla-6.0.0';
    if (!is_file($joomla . '/libraries/vendor/autoload.php')) {
        fwrite(STDERR, "Joomla test distribution missing. Set JOOMLA_TEST_ROOT.\n"); exit(2);
    }
    define('_JEXEC', 1);
    require $joomla . '/libraries/vendor/autoload.php';
    foreach (glob(__DIR__ . '/integration/*.php') as $file) { require $file; }
}
$failures = 0;
$started = microtime(true);
foreach ($tests as $name => $body) {
    try { $body(); echo "PASS $name\n"; }
    catch (Throwable $error) { $failures++; echo "FAIL $name: " . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n"; }
}
printf("%d tests, %d failures, %.3fs\n", count($tests), $failures, microtime(true) - $started);
$reportDirectory = dirname(__DIR__) . '/build';
if (!is_dir($reportDirectory)) { mkdir($reportDirectory, 0770, true); }
file_put_contents($reportDirectory . '/php-test-results.json', json_encode(['tests' => count($tests), 'failures' => $failures, 'timestamp' => gmdate(DATE_ATOM), 'joomla_integration' => in_array('--joomla', $argv, true)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
exit($failures ? 1 : 0);
