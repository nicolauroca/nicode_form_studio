<?php
declare(strict_types=1);

// Destructive only to disposable FormStudio test data in the dedicated fixture.
$root = dirname(__DIR__); $site = $root . '/build/joomla-lifecycle';
define('_JEXEC', 1); define('JPATH_BASE', $site);
require $site . '/includes/defines.php'; require $site . '/includes/framework.php';
$container = Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.cli')->alias(Joomla\CMS\Session\Session::class, 'session.cli')->alias(Joomla\Session\SessionInterface::class, 'session.cli');
$app = $container->get(Joomla\CMS\Application\SiteApplication::class); Joomla\CMS\Factory::$application = $app;
if ($app->get('db') !== 'formstudio_lifecycle' || $app->get('host') !== '127.0.0.1:13367' || $app->get('dbprefix') !== 'lc_' || realpath(JPATH_ROOT) !== realpath($root . '/build/joomla-lifecycle')) { throw new RuntimeException('Refusing to reset a non-disposable fixture.'); }
$db = $container->get(Joomla\Database\DatabaseInterface::class);
$db->setQuery("UPDATE #__extensions SET enabled = 0 WHERE type = 'plugin' AND folder = 'behaviour' AND element = 'compat6'")->execute();
$run = static function (string $phase, array $arguments) use ($site, $root): void {
    $process = proc_open([PHP_BINARY, $site . '/cli/joomla.php', ...$arguments, '--no-interaction'], [0 => ['pipe', 'r'], 1 => ['file', $root . '/build/lifecycle-clean-' . $phase . '.log', 'w'], 2 => ['file', $root . '/build/lifecycle-clean-' . $phase . '-errors.log', 'w']], $pipes, $site);
    if (!is_resource($process)) { throw new RuntimeException('Unable to run fixture installer.'); }
    fclose($pipes[0]); if (proc_close($process) !== 0) { throw new RuntimeException('Fixture installer failed; inspect ignored logs.'); }
};
$db->setQuery("SELECT extension_id FROM #__extensions WHERE type = 'package' AND element = 'pkg_nicode_form_studio'");
$package = (int) $db->loadResult();
if ($package > 0) { $run('remove', ['extension:remove', (string) $package]); }
$sql = file_get_contents($root . '/src/com_nicode_form_studio/administrator/sql/mysql/purge.sql');
foreach (Joomla\CMS\Installer\Installer::splitSql($sql) as $statement) {
    $statement = trim(preg_replace('/^--[^\n]*\n/m', '', $statement)); if ($statement === '') { continue; }
    if (!preg_match('/^DROP TABLE IF EXISTS `#__nicode_form_studio_[a-z_]+`$/D', rtrim($statement, ';'))) { throw new RuntimeException('Unexpected fixture purge statement.'); }
    $db->setQuery($statement)->execute();
}
$remaining = array_filter($db->getTableList(), static fn (string $name): bool => str_starts_with($name, 'lc_nicode_form_studio_'));
if ($remaining !== []) { throw new RuntimeException('Fixture reset did not remove extension tables.'); }
$run('install', ['extension:install', '--path=' . $root . '/build/development-package/pkg_nicode_form_studio.zip']);
$tables = array_filter($db->getTableList(), static fn (string $name): bool => str_starts_with($name, 'lc_nicode_form_studio_'));
if (count($tables) !== 30) { throw new RuntimeException('Fresh installer did not create the complete schema.'); }
file_put_contents($root . '/build/lifecycle-clean-install.json', json_encode(['database' => 'formstudio_lifecycle', 'before_tables' => 0, 'after_tables' => 30, 'package_sha256' => hash_file('sha256', $root . '/build/development-package/pkg_nicode_form_studio.zip'), 'timestamp' => gmdate(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Dedicated fixture reset and clean native package install verified: zero to 30 extension tables.\n";
