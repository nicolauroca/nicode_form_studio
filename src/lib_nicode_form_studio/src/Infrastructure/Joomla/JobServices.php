<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Infrastructure\Joomla;

use Joomla\DI\{Container, ServiceProviderInterface};
use Joomla\Registry\Registry;
use Nicode\FormStudio\Application\{ExportDownloads, JobAdministration, SubmissionAdministration, SubmissionReader};
use Nicode\FormStudio\Export\ExportWorkspace;
use Nicode\FormStudio\Infrastructure\Database\{Connection, FormRepository, JobRepository, SubmissionMaintenance, SubmissionRepository};
use Nicode\FormStudio\Jobs\{BulkSubmissionHandler, ExportCleanupHandler, ExportHandler, FileCleanupHandler, JobWorker, ReindexHandler, RetentionHandler};
use Nicode\FormStudio\Registry\{JobHandlerRegistry, StorageProviderRegistry};
use Nicode\FormStudio\Contract\SearchProviderInterface;

/** Shared job services; export storage is explicitly configured outside the web root. */
final readonly class JobServices implements ServiceProviderInterface
{
    public function __construct(private Registry $config, private string $publicRoot) {}
    public function register(Container $container)
    {
        $container->share(JobRepository::class, static fn (Container $c) => new JobRepository($c->get(Connection::class)));
        $container->share(\Nicode\FormStudio\Infrastructure\Database\UploadJournal::class, static fn (Container $c) => new \Nicode\FormStudio\Infrastructure\Database\UploadJournal($c->get(Connection::class), $c->get(JobRepository::class)));
        $container->share(\Nicode\FormStudio\Infrastructure\Database\PurgeState::class, static fn (Container $c) => new \Nicode\FormStudio\Infrastructure\Database\PurgeState($c->get(Connection::class)));
        $container->share(\Nicode\FormStudio\Application\PackagePurge::class, static fn (Container $c) => new \Nicode\FormStudio\Application\PackagePurge($c->get(Connection::class), $c->get(JobRepository::class), $c->get(\Nicode\FormStudio\Infrastructure\Database\PurgeState::class), $c->get(Authorization::class)->allows(...), hash('sha256', (string) $c->get('config')->get('secret') . ':formstudio-purge')));
        $container->share(\Nicode\FormStudio\Application\FormDeletion::class, static fn (Container $c) => new \Nicode\FormStudio\Application\FormDeletion($c->get(Connection::class), $c->get(JobRepository::class), $c->get(Authorization::class)->allows(...)));
        $container->share(JobMaintenance::class, static fn (Container $c) => new JobMaintenance($c->get(Connection::class), $c->get(JobRepository::class)));
        $container->share(\Nicode\FormStudio\Application\ActionRetries::class, static fn (Container $c) => new \Nicode\FormStudio\Application\ActionRetries($c->get(Connection::class), $c->get(FormRepository::class), $c->get(SubmissionRepository::class), $c->get(\Nicode\FormStudio\Infrastructure\Database\ActionRunRepository::class), $c->get(JobRepository::class), $c->get(\Nicode\FormStudio\Registry\ActionRegistry::class), $c->get(Authorization::class)->allows(...)));
        $container->share(SubmissionMaintenance::class, static fn (Container $c) => new SubmissionMaintenance($c->get(Connection::class), $c->get(JobRepository::class), $c->get(Authorization::class)->allows(...)));
        $container->share(ExportWorkspace::class, fn () => new ExportWorkspace((string) $this->config->get('export_path', ''), $this->publicRoot));
        $container->share(ExportDownloads::class, static fn (Container $c) => new ExportDownloads($c->get(JobRepository::class), $c->get(ExportWorkspace::class), $c->get(Authorization::class)->allows(...)));
        $container->share(JobHandlerRegistry::class, function (Container $c): JobHandlerRegistry {
            $r = new JobHandlerRegistry(); $db = $c->get(Connection::class); $jobs = $c->get(JobRepository::class); $forms = $c->get(FormRepository::class); $authorize = $c->get(Authorization::class)->allows(...);
            $r->register(new \Nicode\FormStudio\Jobs\PackagePurgeHandler($db, $jobs, $c->get(\Nicode\FormStudio\Infrastructure\Database\PurgeState::class), $c->get(\Nicode\FormStudio\Application\PackagePurge::class), $c->get(\Nicode\FormStudio\Application\FormDeletion::class)));
            $r->register(new \Nicode\FormStudio\Jobs\FormDeleteHandler($db, $jobs, $c->get(SubmissionMaintenance::class), $c->get(\Nicode\FormStudio\Application\FormDeletion::class), $c->get(AssetManager::class)->remove(...)));
            $r->register(new ReindexHandler($db, $forms, $c->get(SubmissionRepository::class), $jobs, $authorize));
            $r->register(new \Nicode\FormStudio\Jobs\ActionRetryHandler($forms, $c->get(SubmissionRepository::class), $jobs, $c->get(\Nicode\FormStudio\Actions\ActionEngine::class), $authorize));
            $r->register(new BulkSubmissionHandler($db, $forms, $jobs, $c->get(SearchProviderInterface::class), $c->get(SubmissionAdministration::class), $c->get(SubmissionMaintenance::class), $authorize));
            $r->register(new RetentionHandler($db, $jobs, $c->get(SubmissionMaintenance::class), $authorize));
            $r->register(new \Nicode\FormStudio\Jobs\RetentionDispatchHandler($db, $forms, $jobs));
            $r->register(new \Nicode\FormStudio\Jobs\TechnicalLogCleanupHandler($db));
            foreach (['audit' => 'audit_log_days', 'action' => 'action_history_days'] as $kind => $setting) {
                $currentDays = static function () use ($db, $setting): int {
                    $row = $db->row('SELECT params FROM ' . $db->quote('#__extensions') . ' WHERE type = :type AND element = :element' . $db->sharedLock(), [':type' => 'component', ':element' => 'com_nicode_form_studio']);
                    if ($row === null) { throw new \DomainException('History policy unavailable.'); }
                    $params = json_decode($row['params'], true, 64, JSON_THROW_ON_ERROR);
                    $days = filter_var($params[$setting] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 3650]]);
                    if ($days === false) { throw new \DomainException('Invalid history retention policy.'); }
                    return $days;
                };
                $r->register(new \Nicode\FormStudio\Jobs\OperationalHistoryCleanupHandler($db, $kind, $currentDays));
            }
            $r->register(new \Nicode\FormStudio\Jobs\UploadCleanupHandler($c->get(\Nicode\FormStudio\Infrastructure\Database\UploadJournal::class)));
            $r->register(new \Nicode\FormStudio\Jobs\RateLimitCleanupHandler($db));
            $r->register(new \Nicode\FormStudio\Jobs\AttemptCleanupHandler($db, $c->get(\Nicode\FormStudio\Infrastructure\Database\FormRepository::class), $jobs));
            $r->register(new FileCleanupHandler($c->get(StorageProviderRegistry::class), $jobs));
            if ((string) $this->config->get('export_path', '') !== '') {
                try { $workspace = $c->get(ExportWorkspace::class); }
                catch (\InvalidArgumentException) {
                    $r->register(new \Nicode\FormStudio\Jobs\UnavailableStorageHandler('export-csv'));
                    $r->register(new \Nicode\FormStudio\Jobs\UnavailableStorageHandler('export-json'));
                    $r->register(new \Nicode\FormStudio\Jobs\UnavailableStorageHandler('export-cleanup'));
                    return $c->get(ProviderDiscovery::class)->complete('jobs', $r);
                }
                $r->register(new ExportHandler($db, $forms, $c->get(SubmissionReader::class), $jobs, $workspace, $authorize, $c->get(SearchProviderInterface::class), $c->get(\Nicode\FormStudio\Contract\LifecycleEventsInterface::class)));
                $r->register(new ExportHandler($db, $forms, $c->get(SubmissionReader::class), $jobs, $workspace, $authorize, $c->get(SearchProviderInterface::class), $c->get(\Nicode\FormStudio\Contract\LifecycleEventsInterface::class), 'json'));
                $r->register(new ExportCleanupHandler($db, $jobs, $workspace));
            }
            return $c->get(ProviderDiscovery::class)->complete('jobs', $r);
        });
        $container->share(JobWorker::class, static fn (Container $c) => new JobWorker($c->get(JobRepository::class), $c->get(JobHandlerRegistry::class), $c->get(Connection::class), $c->get(\Nicode\FormStudio\Infrastructure\Database\TechnicalLog::class)));
        $container->share(JobAdministration::class, static fn (Container $c) => new JobAdministration($c->get(Connection::class), $c->get(FormRepository::class), $c->get(JobRepository::class), $c->get(JobHandlerRegistry::class), $c->get(SearchProviderInterface::class), $c->get(Authorization::class)->allows(...)));
    }
}
