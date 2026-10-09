<?php
declare(strict_types=1);

$root = dirname(__DIR__); $lifecycle = in_array('--lifecycle', $argv, true);
$postgres = in_array('--postgresql', $argv, true);
$mysql8 = in_array('--mysql8', $argv, true);
if ($postgres && $mysql8) { throw new InvalidArgumentException('Choose one database fixture.'); }
$site = $root . ($postgres ? '/build/joomla-postgresql' : ($mysql8 ? '/build/joomla-mysql8' : ($lifecycle ? '/build/joomla-lifecycle' : '/build/joomla-6.0.0')));
$databaseName = $postgres ? 'formstudio_joomla_pg' : ($mysql8 ? 'formstudio_joomla_mysql8' : ($lifecycle ? 'formstudio_lifecycle' : 'formstudio_joomla'));
$fixturePrefix = $postgres ? 'pg_' : ($mysql8 ? 'my_' : ($lifecycle ? 'lc_' : 'j6_'));
$fixtureName = $postgres ? 'joomla-postgresql' : ($mysql8 ? 'joomla-mysql8' : ($lifecycle ? 'joomla-lifecycle' : 'joomla'));
$port = $postgres ? 13368 : ($mysql8 ? 13373 : 13367);
$phpOptions = $postgres ? ['-d', 'extension=pgsql', '-d', 'extension=pdo_pgsql'] : [];
if (is_file($site . '/configuration.php')) { echo "Isolated Joomla configuration already exists; not reinstalling.\n"; exit(0); }
if (($lifecycle || $postgres || $mysql8) && !is_file($site . '/installation/joomla.php')) {
    $archive = $root . '/build/joomla-6.0.0.zip';
    if (hash_file('sha256', $archive) !== 'cbf61cbb5e0eacd9db1aed0da93a532d6accbb7d54c36662f3f7432a3e4b573d') { throw new RuntimeException('Official Joomla archive hash mismatch.'); }
    if (!is_dir($site)) { mkdir($site, 0770, true); }
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true || !$zip->extractTo($site)) { throw new RuntimeException('Unable to prepare lifecycle fixture.'); }
    $zip->close();
}
$database = json_decode(ltrim(file_get_contents($root . '/build/database-test' . ($postgres ? '-postgresql' : ($mysql8 ? '-mysql8' : '')) . '.json'), "\xEF\xBB\xBF"), true, 512, JSON_THROW_ON_ERROR);
if ($database['host'] !== '127.0.0.1' || $database['port'] !== $port) { throw new RuntimeException('Refusing non-isolated database.'); }
$pdo = new PDO($postgres ? 'pgsql:host=127.0.0.1;port=13368;dbname=postgres' : 'mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4', $database['user'], $database['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($postgres) {
    if (!$pdo->query("SELECT 1 FROM pg_database WHERE datname = 'formstudio_joomla_pg'")->fetchColumn()) { $pdo->exec("CREATE DATABASE formstudio_joomla_pg ENCODING 'UTF8'"); }
} else { $pdo->exec('CREATE DATABASE IF NOT EXISTS ' . $databaseName . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin'); }
$credentials = ['username' => 'formstudio-test-admin', 'password' => bin2hex(random_bytes(24)), 'site' => $site];
file_put_contents($root . '/build/' . $fixtureName . '-test.json', json_encode($credentials, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$command = [PHP_BINARY, ...$phpOptions, '-d', 'extension=gd', $site . '/installation/joomla.php', 'install', '--site-name=Nicode Form Studio isolated acceptance', '--admin-user=FormStudio Test Administrator', '--admin-username=' . $credentials['username'], '--admin-password=' . $credentials['password'], '--admin-email=admin@example.test', '--db-type=' . ($postgres ? 'pgsql' : 'mysql'), '--db-host=127.0.0.1:' . $database['port'], '--db-user=' . $database['user'], '--db-pass=' . $database['password'], '--db-name=' . $databaseName, '--db-prefix=' . $fixturePrefix, '--no-interaction'];
$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $root . '/build/' . $fixtureName . '-install.log', 'w'], 2 => ['file', $root . '/build/' . $fixtureName . '-install-errors.log', 'w']], $pipes, $site);
if (!is_resource($process)) { throw new RuntimeException('Unable to start Joomla installer.'); }
fclose($pipes[0]); $code = proc_close($process);
// Windows cannot remove the running installer directory until its process exits.
// Only finish this specific cleanup after Joomla wrote its site configuration.
if ($code !== 0 && is_file($site . '/configuration.php') && is_dir($site . '/installation')) {
    $target = realpath($site . '/installation'); $expected = realpath($site) . DIRECTORY_SEPARATOR . 'installation';
    if ($target !== $expected) { throw new RuntimeException('Unexpected installer cleanup target.'); }
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        if ($entry->isLink()) { throw new RuntimeException('Refusing linked installer cleanup entry.'); }
        if ($entry->isDir()) { rmdir($entry->getPathname()); } else { unlink($entry->getPathname()); }
    }
    rmdir($target);
    $check = proc_open([PHP_BINARY, ...$phpOptions, $site . '/cli/joomla.php', 'list', '--quiet'], [0 => ['pipe', 'r'], 1 => ['file', $root . '/build/' . $fixtureName . '-cli-check.log', 'w'], 2 => ['file', $root . '/build/' . $fixtureName . '-cli-check-errors.log', 'w']], $checkPipes, $site);
    if (is_resource($check)) { fclose($checkPipes[0]); $code = proc_close($check); }
}
if ($code !== 0 || !is_file($site . '/configuration.php')) { fwrite(STDERR, "Joomla installation failed; inspect ignored local logs.\n"); exit(1); }
echo "Official Joomla installed into isolated " . $databaseName . " database. Credentials stored only in ignored build/" . $fixtureName . "-test.json.\n";
