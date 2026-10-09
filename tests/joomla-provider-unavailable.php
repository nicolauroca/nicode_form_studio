<?php
declare(strict_types=1);
$root = dirname(__DIR__); $site = $root . '/build/joomla-lifecycle';
$_SERVER['HTTP_HOST'] = '127.0.0.1:13371'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
define('_JEXEC', 1); define('JPATH_BASE', $site);
require $site . '/includes/defines.php'; require $site . '/includes/framework.php';
$container = Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.cli')->alias(Joomla\CMS\Session\Session::class, 'session.cli')->alias(Joomla\Session\SessionInterface::class, 'session.cli');
$app = $container->get(Joomla\CMS\Application\SiteApplication::class); Joomla\CMS\Factory::$application = $app;
if ($app->get('db') !== 'formstudio_lifecycle' || $app->get('host') !== '127.0.0.1:13367' || $app->get('dbprefix') !== 'lc_') { throw new RuntimeException('Refusing non-isolated provider test.'); }
$app->createExtensionNamespaceMap(); $app->loadLanguage($container->get(Joomla\CMS\Language\LanguageFactoryInterface::class)->createLanguage('en-GB', false)); $app->loadIdentity(new Joomla\CMS\User\User());
$runtime = $app->bootComponent('com_nicode_form_studio')->runtime($app);
$form = filter_var($argv[1] ?? '', FILTER_VALIDATE_INT); $version = filter_var($argv[2] ?? '', FILTER_VALIDATE_INT);
if (!$form || !$version) { throw new RuntimeException('Expected fixture identity.'); }
if ($runtime->get(Nicode\FormStudio\Registry\DataSourceRegistry::class)->has('fixture.department')) { throw new RuntimeException('Disabled provider remained available in a fresh process.'); }
$spec = $runtime->get(Nicode\FormStudio\Infrastructure\Database\FormRepository::class)->version($form, $version);
if (!isset($spec->toArray()['provider_dependencies']['sources']['fixture.department'])) { throw new RuntimeException('Historical spec unavailable.'); }
$context = new Nicode\FormStudio\Submission\RequestContext(0, [1], 'en-GB', 'provider-test', hash('sha256', 'provider-test'), true);
try {
    $runtime->get(Nicode\FormStudio\Application\FormDisplay::class)->render($form, $context, 'fixture', '/index.php', 'csrf');
    throw new RuntimeException('Public render executed without required provider.');
} catch (DomainException) {}
$db = $runtime->get(Nicode\FormStudio\Infrastructure\Database\Connection::class);
$count = static fn (): int => (int) $db->row('SELECT COUNT(*) AS total FROM ' . $db->table('submissions') . ' WHERE form_id = :form', [':form' => $form])['total'];
$before = $count();
$result = $runtime->get(Nicode\FormStudio\Application\SubmissionPipeline::class)->submit(new Nicode\FormStudio\Submission\SubmitRequest($form, $version, '', []), $context);
if (($result['accepted'] ?? true) || $before !== $count()) { throw new RuntimeException('Submission persisted without required provider.'); }
echo "Disabled provider: historical snapshot remains readable; public render and submit fail closed before persistence.\n";
