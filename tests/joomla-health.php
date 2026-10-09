<?php
declare(strict_types=1);
require __DIR__ . '/joomla-config.php';
$db = new Nicode\FormStudio\Infrastructure\Database\Connection($container->get(Joomla\Database\DatabaseInterface::class));
$safeConfig = new Joomla\Registry\Registry(['storage_path' => $private, 'export_path' => $private . '/missing-health-test']);
$health = new Nicode\FormStudio\Infrastructure\Joomla\SystemHealth($db, $safeConfig, $site, static fn (int $actor, ?int $form, string $permission): bool => $actor === 1);
try { $health->report(2); throw new RuntimeException('Denied diagnostics returned.'); } catch (DomainException) {}
$report = $health->report(1);
$expectedLimits = ['post_max_size', 'upload_max_filesize', 'max_file_uploads', 'max_input_vars', 'max_input_nesting_level', 'max_multipart_body_parts', 'memory_limit', 'max_input_time', 'max_execution_time', 'file_uploads'];
if (array_keys($report['php_limits']) !== $expectedLimits) { throw new RuntimeException('PHP diagnostics escaped the numeric allowlist.'); }
foreach ($report['php_limits'] as $directive => $value) {
    if ($value !== null && !preg_match('/^-?[0-9]+[KMG]?$/iD', $value)) { throw new RuntimeException('Non-numeric PHP configuration disclosed.'); }
    if ($directive !== 'file_uploads' && ini_get($directive) !== false && $value !== ini_get($directive)) { throw new RuntimeException('PHP capacity value does not match the active runtime.'); }
}
if ($report['checks']['export_path']['status'] !== 'unavailable' || !in_array($report['checks']['storage_path']['status'], ['ok', 'low_space'], true)) { throw new RuntimeException('Storage health classification failed.'); }
if (str_contains(json_encode($report, JSON_THROW_ON_ERROR), 'missing-health-test')) { throw new RuntimeException('Private path exposed.'); }
foreach ($report['checks'] as $check) { if (isset($check['count']) && $check['count'] > 100) { throw new RuntimeException('Unbounded health result.'); } }
// Invalid export configuration must not prevent constructing administrative job services.
$services = new Joomla\DI\Container($container);
$services->registerServiceProvider(new Nicode\FormStudio\Infrastructure\Joomla\RuntimeProvider($app, $safeConfig, $site));
$handlers = $services->get(Nicode\FormStudio\Registry\JobHandlerRegistry::class);
if (!$handlers->has('reindex') || $handlers->get('export-csv')->metadata()['available'] !== false) { throw new RuntimeException('Export failure contaminated job registry.'); }
$lease = new Nicode\FormStudio\Jobs\JobLease(1, Nicode\FormStudio\Domain\Uuid::create(), 'export-csv', 1, [], [], str_repeat('a', 64), 1, 0, 0);
try { $handlers->get('export-csv')->run($lease, 1); throw new RuntimeException('Unavailable storage did not defer work.'); }
catch (Nicode\FormStudio\Jobs\RetryableJobFailure $error) { if ($error->resultCode !== 'storage_unavailable' || $error->delaySeconds !== 300) { throw new RuntimeException('Unsafe storage failure result.'); } }
echo "Native health: ACL denial, safe private storage probes, bounded counts and isolated retryable export failure verified.\n";
$safeConfig->set('storage_path', $private . '/missing-upload-test');
$noStorage = new Joomla\DI\Container($container);
$noStorage->registerServiceProvider(new Nicode\FormStudio\Infrastructure\Joomla\RuntimeProvider($app, $safeConfig, $site));
if ($noStorage->get(Nicode\FormStudio\Registry\StorageProviderRegistry::class)->get('local')->metadata()['available'] !== false || !$noStorage->get(Nicode\FormStudio\Registry\JobHandlerRegistry::class)->has('retention')) { throw new RuntimeException('Unavailable uploads blocked independent jobs.'); }
if (!$noStorage->get(Nicode\FormStudio\Application\SubmissionPipeline::class) instanceof Nicode\FormStudio\Application\SubmissionPipeline) { throw new RuntimeException('Unavailable uploads blocked text submissions.'); }
$nativeHealth = $services->get(Nicode\FormStudio\Infrastructure\Joomla\SystemHealth::class)->report((int) $admin->id);
if (!in_array($nativeHealth['checks']['source_cache']['reason'] ?? '', ['cache_disabled', 'cache_read_unverified', 'cache_request_only'], true)) { throw new RuntimeException('Native source cache mode was not diagnosed.'); }
$cachePathOriginal = $app->get('cache_path', JPATH_CACHE);
try {
    $app->set('cache_path', $private . '/missing-cache-health-private');
    $cacheReport = $services->get(Nicode\FormStudio\Infrastructure\Joomla\SystemHealth::class)->report((int) $admin->id);
    if ($cacheReport['checks']['cache_directory']['status'] !== 'unavailable' || str_contains(json_encode($cacheReport), 'missing-cache-health-private')) { throw new RuntimeException('Configured cache directory was ignored or exposed.'); }
} finally { $app->set('cache_path', $cachePathOriginal); }
$localCacheServices = new Joomla\DI\Container($container);
$localCacheServices->registerServiceProvider(new Nicode\FormStudio\Infrastructure\Joomla\RuntimeProvider($app, $safeConfig, $site));
$localCacheServices->set(Nicode\FormStudio\Contract\CacheInterface::class, new Nicode\FormStudio\DataSource\RequestCache(), true);
if ($localCacheServices->get(Nicode\FormStudio\Infrastructure\Joomla\SystemHealth::class)->report((int) $admin->id)['checks']['source_cache'] !== ['status' => 'warning', 'reason' => 'cache_request_only']) { throw new RuntimeException('Request-only cache fallback was hidden by native diagnostics.'); }
foreach (['schema', 'schema_columns', 'schema_indexes', 'schema_types', 'schema_foreign_keys', 'package', 'database_version', 'search_provider', 'configuration_audit'] as $key) { if ($nativeHealth['checks'][$key]['status'] !== 'ok') { throw new RuntimeException('Native health probe failed: ' . $key); } }
try {
    $db->execute('UPDATE ' . $db->quote('#__extensions') . " SET enabled = 0 WHERE type = 'plugin' AND folder = 'extension' AND element = 'nicode_form_studio'");
    if ($services->get(Nicode\FormStudio\Infrastructure\Joomla\SystemHealth::class)->report((int) $admin->id)['checks']['configuration_audit']['status'] !== 'warning') { throw new RuntimeException('Disabled audit integration was not diagnosed.'); }
} finally {
    $db->execute('UPDATE ' . $db->quote('#__extensions') . " SET enabled = 1 WHERE type = 'plugin' AND folder = 'extension' AND element = 'nicode_form_studio'");
}
if (count($nativeHealth['versions']) !== 6 || $nativeHealth['checks']['scheduled_task']['status'] !== 'not_configured') { throw new RuntimeException('Health confused manual fixtures with automatic scheduling or omitted versions.'); }
$mailOriginal = [];
foreach (['mailonline', 'mailfrom', 'mailer', 'smtphost', 'smtpport', 'smtpsecure', 'smtpauth', 'smtpuser', 'smtppass'] as $key) { $mailOriginal[$key] = $app->get($key); }
try {
    foreach (['mailonline' => true, 'mailfrom' => 'sender@example.test', 'mailer' => 'smtp', 'smtphost' => '', 'smtpport' => 587, 'smtpsecure' => 'tls', 'smtpauth' => true, 'smtpuser' => 'health-private-user', 'smtppass' => 'health-private-password'] as $key => $value) { $app->set($key, $value); }
    $smtpHealth = $services->get(Nicode\FormStudio\Infrastructure\Joomla\SystemHealth::class)->report((int) $admin->id);
    if ($smtpHealth['checks']['mail_configuration'] !== ['status' => 'not_configured', 'reason' => 'mail_smtp_host_invalid'] || str_contains(json_encode($smtpHealth), 'health-private')) { throw new RuntimeException('Native SMTP health ignored missing host or exposed credentials.'); }
    $app->set('smtphost', 'health-private-host.invalid');
    $smtpHealth = $services->get(Nicode\FormStudio\Infrastructure\Joomla\SystemHealth::class)->report((int) $admin->id);
    if ($smtpHealth['checks']['mail_configuration'] !== ['status' => 'ok', 'reason' => 'mail_delivery_unverified'] || str_contains(json_encode($smtpHealth), 'health-private')) { throw new RuntimeException('Native SMTP health claimed delivery or leaked configuration.'); }
} finally { foreach ($mailOriginal as $key => $value) { $app->set($key, $value); } }
$failedProbe = new Nicode\FormStudio\Infrastructure\Joomla\SystemHealth($db, new Joomla\Registry\Registry(), $site, static fn (): bool => true, ['test_failure' => static fn () => throw new RuntimeException('secret-path-and-payload')]);
$failedReport = $failedProbe->report(1);
if ($failedReport['checks']['test_failure']['status'] !== 'unavailable' || str_contains(json_encode($failedReport, JSON_THROW_ON_ERROR), 'secret-path-and-payload')) { throw new RuntimeException('Health exposed a probe exception.'); }
