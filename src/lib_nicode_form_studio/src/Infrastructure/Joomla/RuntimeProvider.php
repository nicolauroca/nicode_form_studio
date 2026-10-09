<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Infrastructure\Joomla;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Captcha\CaptchaRegistry;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Router\Route;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Registry\Registry;
use Nicode\FormStudio\Actions\{ActionEngine, EmailAction, NavigationAction, TokenTemplate, WebhookAction};
use Nicode\FormStudio\Application\{FormAdministration, FormDisplay, FormListing, FormPreview, PublicationReadiness, SubmissionPipeline};
use Nicode\FormStudio\Compiler\FormCompiler;
use Nicode\FormStudio\Contract\{CaptchaAdapterInterface, MailTransportInterface, SecretStoreInterface};
use Nicode\FormStudio\Contract\LifecycleEventsInterface;
use Nicode\FormStudio\Contract\SearchProviderInterface;
use Nicode\FormStudio\DataSource\{OptionResolver, RequestCache, StaticDataSource};
use Nicode\FormStudio\Field\CoreFieldTypes;
use Nicode\FormStudio\Http\{CurlTransport, DestinationPolicy};
use Nicode\FormStudio\Infrastructure\Database\{ActionRunRepository, Connection, FormRepository, RateLimiter, SubmissionRepository};
use Nicode\FormStudio\Registry\{ActionRegistry, DataSourceRegistry, FieldTypeRegistry, RuleEffectRegistry, RuleOperatorRegistry, StorageProviderRegistry, ValidatorRegistry};
use Nicode\FormStudio\Rendering\{CoreFieldRenderer, FieldRendererRegistry, FormRenderer, PublicSpec};
use Nicode\FormStudio\Rules\{ConditionEvaluator, RuleEngine};
use Nicode\FormStudio\Search\IndexProjector;
use Nicode\FormStudio\Security\{AttemptTokens, CaptchaPolicy, EnvironmentSecrets, PublicAccess, RedirectPolicy};
use Nicode\FormStudio\Storage\{HttpUploadGateway, LocalStorage, UploadInspector};
use Nicode\FormStudio\Submission\PostSubmit;
use Nicode\FormStudio\Validation\ValidationEngine;

/** Request-scoped composition root shared by component, module and admin. */
final readonly class RuntimeProvider implements ServiceProviderInterface
{
    public function __construct(private CMSApplicationInterface $app, private Registry $config, private string $publicRoot) {}

    public function register(Container $container)
    {
        $container->registerServiceProvider(new JobServices($this->config, $this->publicRoot));
        $container->share(ProviderDiscovery::class, static fn (Container $c) => new ProviderDiscovery($c->get(DispatcherInterface::class), static fn (DispatcherInterface $dispatcher) => \Joomla\CMS\Plugin\PluginHelper::importPlugin('formstudio', dispatcher: $dispatcher)));
        $container->share(LifecycleEventsInterface::class, static fn (Container $c) => new LifecycleEvents($c->get(DispatcherInterface::class), static fn (DispatcherInterface $dispatcher) => \Joomla\CMS\Plugin\PluginHelper::importPlugin('formstudio', dispatcher: $dispatcher), static fn () => $c->get(\Nicode\FormStudio\Infrastructure\Database\TechnicalLog::class)->record('ERROR', 'extension.failed')));
        $container->share(SystemHealth::class, fn (Container $c) => new SystemHealth($c->get(Connection::class), $this->config, $this->publicRoot, $c->get(Authorization::class)->allows(...), [
            'schema_indexes' => static fn () => (new \Nicode\FormStudio\Health\SchemaIndexes($c->get(DatabaseInterface::class)))->inspect(),
            'schema_types' => static fn () => (new \Nicode\FormStudio\Health\SchemaTypes($c->get(DatabaseInterface::class)))->inspect(),
            'schema_foreign_keys' => static fn () => (new \Nicode\FormStudio\Health\SchemaForeignKeys($c->get(DatabaseInterface::class)))->inspect(),
            'database_version' => static fn () => ['status' => 'ok', 'detail' => substr($c->get(DatabaseInterface::class)->getServerType() . ' ' . $c->get(DatabaseInterface::class)->getVersion(), 0, 200)],
            'mail_configuration' => function (): array {
                $mail = [];
                foreach (['mailonline', 'mailfrom', 'mailer', 'smtphost', 'smtpport', 'smtpsecure', 'smtpauth', 'smtpuser', 'smtppass'] as $key) { $mail[$key] = $this->app->get($key); }
                return \Nicode\FormStudio\Health\MailConfiguration::inspect($mail);
            },
            'captcha' => static function () use ($c): array { $adapter = $c->get(CaptchaAdapterInterface::class); $adapter->assertAvailable(new CaptchaPolicy('inherit')); return ['status' => 'ok', 'detail' => substr(implode(', ', $adapter->available()), 0, 200)]; },
            'search_provider' => static fn () => ['status' => ($c->get(SearchProviderInterface::class)->metadata()['available'] ?? true) ? 'ok' : 'unavailable', 'detail' => $c->get(SearchProviderInterface::class)->id() . ' ' . $c->get(SearchProviderInterface::class)->version()],
            'cache_directory' => function (): array {
                $path = $this->app->get('cache_path', JPATH_CACHE);
                return ['status' => is_string($path) && $path !== '' && is_dir($path) && is_writable($path) ? 'ok' : 'unavailable'];
            },
            'source_cache' => static function () use ($c): array {
                $cache = $c->get(\Nicode\FormStudio\Contract\CacheInterface::class);
                return $cache instanceof SourceCache ? $cache->diagnostics() : ['status' => 'warning', 'reason' => 'cache_request_only'];
            },
        ]));
        $container->share(Connection::class, fn (Container $c) => new Connection($c->get(DatabaseInterface::class)));
        $container->share(\Nicode\FormStudio\Infrastructure\Database\TechnicalLog::class, fn (Container $c) => new \Nicode\FormStudio\Infrastructure\Database\TechnicalLog($c->get(Connection::class), (bool) $this->config->get('technical_debug', false)));
        $container->share(\Nicode\FormStudio\Application\AuditLog::class, static fn (Container $c) => new \Nicode\FormStudio\Application\AuditLog($c->get(Connection::class), $c->get(Authorization::class)->allows(...)));
        $container->share(\Nicode\FormStudio\Application\OptionSets::class, static fn (Container $c) => new \Nicode\FormStudio\Application\OptionSets($c->get(Connection::class), $c->get(Authorization::class)->allows(...)));
        $container->share(FieldTypeRegistry::class, static function (Container $c): FieldTypeRegistry { $r = new FieldTypeRegistry(); CoreFieldTypes::register($r); return $c->get(ProviderDiscovery::class)->complete('fields', $r); });
        $container->share(ValidatorRegistry::class, static fn (Container $c) => $c->get(ProviderDiscovery::class)->complete('validators', ValidatorRegistry::core()));
        $container->share(RuleOperatorRegistry::class, static fn (Container $c) => $c->get(ProviderDiscovery::class)->complete('operators', RuleOperatorRegistry::core()));
        $container->share(RuleEffectRegistry::class, static fn (Container $c) => $c->get(ProviderDiscovery::class)->complete('effects', RuleEffectRegistry::core()));
        $container->share(DataSourceRegistry::class, static function (Container $c): DataSourceRegistry {
            $r = new DataSourceRegistry(); $r->register(new StaticDataSource()); $r->register(new StaticDataSource('option_set'));
            foreach (['categories', 'articles'] as $entity) { $r->register(new EntitySource($c->get(Connection::class), $entity)); }
            return $c->get(ProviderDiscovery::class)->complete('sources', $r);
        });
        $container->share(SecretStoreInterface::class, static fn () => new EnvironmentSecrets());
        $container->share(TokenTemplate::class, static fn () => new TokenTemplate());
        $container->share(RedirectPolicy::class, fn () => new RedirectPolicy($this->hosts('redirect_hosts')));
        $container->share(DestinationPolicy::class, fn () => new DestinationPolicy($this->hosts('webhook_hosts')));
        $container->share(MailTransportInterface::class, function (Container $c): MailTransportInterface {
            $factory = $c->get(MailerFactoryInterface::class); $app = $this->app;
            return new class($factory, $app) implements MailTransportInterface {
                public function __construct(private MailerFactoryInterface $factory, private CMSApplicationInterface $app) {}
                public function send(\Nicode\FormStudio\Actions\MailMessage $message): void
                {
                    try { $transport = new MailTransport($this->factory, (string) $this->app->get('mailfrom'), (string) $this->app->get('fromname')); }
                    catch (\InvalidArgumentException) { throw new \Nicode\FormStudio\Actions\ActionFailure('mail_configuration'); }
                    $transport->send($message);
                }
            };
        });
        $container->share(ActionRegistry::class, function (Container $c): ActionRegistry {
            $r = new ActionRegistry(); $templates = $c->get(TokenTemplate::class); $mail = $c->get(MailTransportInterface::class);
            // Resolve storage lazily, after provider discovery has finished registering actions.
            $attachments = new class($c) implements \Nicode\FormStudio\Contract\MailAttachmentResolverInterface {
                public function __construct(private Container $container) {}
                public function resolve(array $selectedFields, \Nicode\FormStudio\Actions\ActionContext $context): array
                {
                    return (new \Nicode\FormStudio\Application\StoredMailAttachments($this->container->get(Connection::class), $this->container->get(StorageProviderRegistry::class)))->resolve($selectedFields, $context);
                }
            };
            $r->register(new EmailAction($mail, $templates, attachments: $attachments)); $r->register(new EmailAction($mail, $templates, true, $attachments));
            $policy = $c->get(DestinationPolicy::class);
            $r->register(new WebhookAction(new CurlTransport($policy), $policy, $c->get(SecretStoreInterface::class), $templates));
            $r->register(new NavigationAction($c->get(RedirectPolicy::class), function (int $id) use ($c): string {
                $item = $c->get(\Joomla\CMS\Menu\MenuFactoryInterface::class)->createMenu('site', ['language' => $this->app->getLanguage()])->getItem($id);
                $user = $this->app->getIdentity();
                if (!$item || !$user || !in_array((int) $item->access, array_map('intval', $user->getAuthorisedViewLevels()), true) || !in_array($item->language, ['*', $this->app->getLanguage()->getTag()], true)) { throw new \DomainException('Menu destination unavailable.'); }
                return Route::link('site', 'index.php?Itemid=' . $id, false);
            }));
            return $c->get(ProviderDiscovery::class)->complete('actions', $r);
        });
        $container->share(FormCompiler::class, static fn (Container $c) => new FormCompiler($c->get(FieldTypeRegistry::class), $c->get(ActionRegistry::class), $c->get(DataSourceRegistry::class), $c->get(ValidatorRegistry::class), $c->get(RuleOperatorRegistry::class), $c->get(RuleEffectRegistry::class)));
        $container->share(\Nicode\FormStudio\Registry\ProviderDependencies::class, static fn (Container $c) => new \Nicode\FormStudio\Registry\ProviderDependencies(['fields' => $c->get(FieldTypeRegistry::class), 'actions' => $c->get(ActionRegistry::class), 'sources' => $c->get(DataSourceRegistry::class), 'validators' => $c->get(ValidatorRegistry::class), 'operators' => $c->get(RuleOperatorRegistry::class), 'effects' => $c->get(RuleEffectRegistry::class)]));
        $container->share(FormRepository::class, fn (Container $c) => new FormRepository($c->get(Connection::class), $c->get(FormCompiler::class), $this->config->get('default_persistence', 'full')));
        $container->share(\Nicode\FormStudio\Domain\DefinitionRemapper::class, static fn (Container $c) => new \Nicode\FormStudio\Domain\DefinitionRemapper(['fields' => $c->get(FieldTypeRegistry::class), 'actions' => $c->get(ActionRegistry::class), 'sources' => $c->get(DataSourceRegistry::class), 'validators' => $c->get(ValidatorRegistry::class), 'operators' => $c->get(RuleOperatorRegistry::class), 'effects' => $c->get(RuleEffectRegistry::class)]));
        $container->share(\Nicode\FormStudio\Application\FormDuplicator::class, static fn (Container $c) => new \Nicode\FormStudio\Application\FormDuplicator($c->get(Connection::class), $c->get(FormRepository::class), $c->get(FormAdministration::class), $c->get(\Nicode\FormStudio\Domain\DefinitionRemapper::class), $c->get(AssetManager::class)->copyPermissions(...)));
        $container->share(\Nicode\FormStudio\Transfer\DefinitionPackage::class, static fn (Container $c) => new \Nicode\FormStudio\Transfer\DefinitionPackage(['fields' => $c->get(FieldTypeRegistry::class), 'actions' => $c->get(ActionRegistry::class), 'sources' => $c->get(DataSourceRegistry::class), 'validators' => $c->get(ValidatorRegistry::class), 'operators' => $c->get(RuleOperatorRegistry::class), 'effects' => $c->get(RuleEffectRegistry::class)]));
        $container->share(\Nicode\FormStudio\Transfer\ImportPreview::class, static fn (Container $c) => new \Nicode\FormStudio\Transfer\ImportPreview($c->get(\Nicode\FormStudio\Transfer\DefinitionPackage::class), $c->get(FormCompiler::class)));
        $container->share(\Nicode\FormStudio\Transfer\ImportReviewToken::class, fn () => new \Nicode\FormStudio\Transfer\ImportReviewToken($this->key('definition-import-review'), time(...)));
        $container->share(\Nicode\FormStudio\Application\FormExchange::class, static fn (Container $c) => new \Nicode\FormStudio\Application\FormExchange($c->get(Connection::class), $c->get(FormRepository::class), $c->get(FormAdministration::class), $c->get(\Nicode\FormStudio\Transfer\DefinitionPackage::class), $c->get(\Nicode\FormStudio\Transfer\ImportPreview::class), $c->get(\Nicode\FormStudio\Transfer\ImportReviewToken::class), $c->get(\Nicode\FormStudio\Domain\DefinitionRemapper::class), $c->get(Authorization::class)->allows(...), $c->get(LifecycleEventsInterface::class)));
        $container->share(ConditionEvaluator::class, static fn (Container $c) => new ConditionEvaluator($c->get(RuleOperatorRegistry::class)));
        $container->share(\Nicode\FormStudio\Application\Templates::class, static fn (Container $c) => new \Nicode\FormStudio\Application\Templates($c->get(Connection::class), $c->get(FormAdministration::class), $c->get(\Nicode\FormStudio\Application\FormExchange::class), $c->get(Authorization::class)->allows(...)));
        $container->share(\Nicode\FormStudio\Application\DataSources::class, static fn (Container $c) => new \Nicode\FormStudio\Application\DataSources($c->get(Connection::class), $c->get(FormAdministration::class), $c->get(\Nicode\FormStudio\Application\FormExchange::class), $c->get(DataSourceRegistry::class), $c->get(FieldTypeRegistry::class), $c->get(\Nicode\FormStudio\Domain\DefinitionRemapper::class), $c->get(Authorization::class)->allows(...)));
        $container->share(\Nicode\FormStudio\Contract\CacheInterface::class, static function (Container $c): \Nicode\FormStudio\Contract\CacheInterface {
            try {
                $controller = $c->get(\Joomla\CMS\Cache\CacheControllerFactoryInterface::class)->createCacheController('output', ['defaultgroup' => 'com_nicode_form_studio.sources', 'lifetime' => 1440]);
                return new SourceCache($controller->cache);
            } catch (\Throwable) { return new RequestCache(); }
        });
        $container->share(OptionResolver::class, static fn (Container $c) => new OptionResolver($c->get(DataSourceRegistry::class), $c->get(\Nicode\FormStudio\Contract\CacheInterface::class), $c->get(LifecycleEventsInterface::class), static fn () => $c->get(\Nicode\FormStudio\Infrastructure\Database\TechnicalLog::class)->record('ERROR', 'datasource.failed')));
        $container->share(RuleEngine::class, static fn (Container $c) => new RuleEngine($c->get(ConditionEvaluator::class), $c->get(RuleEffectRegistry::class), $c->get(FieldTypeRegistry::class), options: $c->get(OptionResolver::class)));
        $container->share(ValidationEngine::class, static fn (Container $c) => new ValidationEngine($c->get(FieldTypeRegistry::class), $c->get(RuleEngine::class), $c->get(ValidatorRegistry::class)));
        $container->share(\Nicode\FormStudio\Application\FormOptions::class, static fn (Container $c) => new \Nicode\FormStudio\Application\FormOptions($c->get(FormRepository::class), $c->get(PublicAccess::class), $c->get(AttemptTokens::class), $c->get(ValidationEngine::class), new RateLimiter($c->get(Connection::class)), $c->get(\Nicode\FormStudio\Registry\ProviderDependencies::class)));
        $container->share(\Nicode\FormStudio\Application\FormRows::class, static fn (Container $c) => new \Nicode\FormStudio\Application\FormRows($c->get(FormRepository::class), $c->get(PublicAccess::class), $c->get(AttemptTokens::class), new RateLimiter($c->get(Connection::class)), $c->get(\Nicode\FormStudio\Registry\ProviderDependencies::class)));
        $container->share(CaptchaAdapterInterface::class, fn (Container $c) => new CaptchaAdapter($c->get(CaptchaRegistry::class), new CaptchaPolicy((string) $this->config->get('captcha_mode', 'joomla'), $this->config->get('captcha_provider')), $this->app->get('captcha'), (bool) $this->config->get('allow_no_captcha', true)));
        $container->share(AttemptTokens::class, fn () => new AttemptTokens($this->key('attempt')));
        $container->share(PublicAccess::class, static fn (Container $c) => new PublicAccess($c->get(\Nicode\FormStudio\Infrastructure\Database\PurgeState::class)->active(...)));
        $container->share(RequestAdapter::class, fn () => new RequestAdapter($this->app, $this->key('request-context')));
        $container->share(Authorization::class, static fn (Container $c) => new Authorization($c->get(UserFactoryInterface::class), $c->get(Connection::class)));
        $container->share(FormPermissions::class, static fn (Container $c) => new FormPermissions($c->get(Connection::class), $c->get(Authorization::class)));
        $container->share(AssetManager::class, static fn (Container $c) => new AssetManager($c->get(DatabaseInterface::class), $c->get(DispatcherInterface::class), $c->get(Connection::class)));
        $container->share(PublicationReadiness::class, fn (Container $c) => new PublicationReadiness($c->get(StorageProviderRegistry::class), $c->get(SecretStoreInterface::class), fn (): bool => (bool) $this->app->get('mailonline', true) && \Nicode\FormStudio\Actions\MailMessage::validAddress((string) $this->app->get('mailfrom')) && !preg_match('/[\x00-\x1f\x7f]/', (string) $this->app->get('fromname')), $c->get(FieldRendererRegistry::class), $c->get(PublicSpec::class)));
        $container->share(FormAdministration::class, static fn (Container $c) => new FormAdministration($c->get(FormRepository::class), $c->get(Connection::class), $c->get(Authorization::class)->allows(...), $c->get(AssetManager::class)->attach(...), $c->get(CaptchaAdapterInterface::class), $c->get(PublicationReadiness::class)));
        $container->share(FormListing::class, fn (Container $c) => new FormListing($c->get(Connection::class), new \Nicode\FormStudio\Search\CursorCodec($this->key('form-list-cursor')), $c->get(Authorization::class)->allows(...), $c->get(Authorization::class)->formCapabilities(...)));
        $container->share(\Nicode\FormStudio\Application\FormDiagnostics::class, static fn (Container $c) => new \Nicode\FormStudio\Application\FormDiagnostics($c->get(FormRepository::class), $c->get(FormCompiler::class), $c->get(CaptchaAdapterInterface::class), $c->get(PublicationReadiness::class), $c->get(\Nicode\FormStudio\Registry\ProviderDependencies::class)));
        $container->share(\Nicode\FormStudio\Application\OperationsDashboard::class, static fn (Container $c) => new \Nicode\FormStudio\Application\OperationsDashboard($c->get(Connection::class), $c->get(Authorization::class)->allows(...), $c->get(Authorization::class)->formCapabilities(...), $c->get(Authorization::class)->submissionCapabilities(...), $c->get(\Nicode\FormStudio\Application\FormDiagnostics::class)->inspect(...), time(...), static fn (int $actor): array => $c->get(SystemHealth::class)->report($actor)['checks']));
        $container->share(FieldRendererRegistry::class, static function (Container $c): FieldRendererRegistry {
            $r = new FieldRendererRegistry(); $core = new FieldTypeRegistry(); CoreFieldTypes::register($core);
            foreach (array_keys($core->metadata()) as $id) { $r->register($id, new CoreFieldRenderer()); }
            $c->get(ProviderDiscovery::class)->complete('renderers', $r);
            return $r;
        });
        $container->share(\Nicode\FormStudio\Rendering\BrowserProviders::class, function (Container $c): \Nicode\FormStudio\Rendering\BrowserProviders {
            return new \Nicode\FormStudio\Rendering\BrowserProviders(['fields' => $c->get(FieldTypeRegistry::class), 'operators' => $c->get(RuleOperatorRegistry::class), 'effects' => $c->get(RuleEffectRegistry::class), 'validators' => $c->get(ValidatorRegistry::class)], function (string $name, string $type): string {
                if (!$this->app instanceof \Joomla\CMS\Application\CMSWebApplicationInterface) { throw new \DomainException('Browser assets require a web document.'); }
                $asset = $this->app->getDocument()->getWebAssetManager()->getAsset($type, $name);
                if ($type === 'script' && $asset->getAttribute('type') !== 'module') { throw new \DomainException('Browser provider requires an ES module asset.'); }
                $uri = $asset->getUri();
                return $uri === '' ? '' : $uri . (str_contains($uri, '?') ? '&' : '?') . 'nfs_provider=' . rawurlencode((string) $asset->getVersion());
            });
        });
        $container->share(PublicSpec::class, static fn (Container $c) => new PublicSpec($c->get(FieldTypeRegistry::class), $c->get(\Nicode\FormStudio\Rendering\BrowserProviders::class)));
        $container->share(FormRenderer::class, static function (Container $c): FormRenderer {
            $filter = new \Joomla\CMS\Filter\InputFilter(['p', 'br', 'strong', 'em', 'ul', 'ol', 'li', 'a', 'h2', 'h3', 'span'], ['href', 'title'], 0, 0);
            return new FormRenderer($c->get(FieldRendererRegistry::class), $c->get(PublicSpec::class), static fn (string $html): string => $filter->clean($html, 'HTML'));
        });
        $container->share(FormDisplay::class, static fn (Container $c) => new FormDisplay($c->get(FormRepository::class), $c->get(FieldTypeRegistry::class), $c->get(RuleEngine::class), $c->get(FormRenderer::class), $c->get(PublicAccess::class), $c->get(AttemptTokens::class), $c->get(CaptchaAdapterInterface::class), $c->get(\Nicode\FormStudio\Registry\ProviderDependencies::class), $c->get(LifecycleEventsInterface::class)));
        $container->share(FormPreview::class, static fn (Container $c) => new FormPreview($c->get(FormAdministration::class), $c->get(FormCompiler::class), $c->get(FieldTypeRegistry::class), $c->get(RuleEngine::class), $c->get(FormRenderer::class), $c->get(LifecycleEventsInterface::class), $c->get(ValidationEngine::class)));
        $container->share(StorageProviderRegistry::class, function (Container $c): StorageProviderRegistry {
            $r = new StorageProviderRegistry(); $path = $this->config->get('storage_path', '');
            $cleanup = static fn (string $provider, string $key): int => $c->get(\Nicode\FormStudio\Infrastructure\Database\JobRepository::class)->enqueue('file-cleanup', ['objects' => [['provider' => $provider, 'storage_key' => $key]]], 0);
            try { $r->register(new LocalStorage(is_string($path) ? $path : '', $this->publicRoot, $cleanup)); }
            catch (\InvalidArgumentException) {
                $r->register(new \Nicode\FormStudio\Storage\UnavailableStorage());
                try { $c->get(\Nicode\FormStudio\Infrastructure\Database\TechnicalLog::class)->record('ERROR', 'storage.unavailable'); } catch (\Throwable) {}
            }
            return $c->get(ProviderDiscovery::class)->complete('storage', $r);
        });
        $container->share(SubmissionRepository::class, fn (Container $c) => new SubmissionRepository($c->get(Connection::class), new IndexProjector($c->get(FieldTypeRegistry::class)), $this->key('submission-fingerprint'), $c->get(\Nicode\FormStudio\Infrastructure\Database\UploadJournal::class)));
        $container->share(\Nicode\FormStudio\Search\SqlSearchProvider::class, fn (Container $c) => new \Nicode\FormStudio\Search\SqlSearchProvider($c->get(Connection::class), $c->get(FieldTypeRegistry::class), new \Nicode\FormStudio\Search\CursorCodec($this->key('submission-search-cursor'))));
        $container->share(\Nicode\FormStudio\Registry\SearchProviderRegistry::class, static function (Container $c): \Nicode\FormStudio\Registry\SearchProviderRegistry {
            $r = new \Nicode\FormStudio\Registry\SearchProviderRegistry(); $r->register($c->get(\Nicode\FormStudio\Search\SqlSearchProvider::class));
            return $c->get(ProviderDiscovery::class)->complete('search', $r);
        });
        $container->share(SearchProviderInterface::class, function (Container $c): SearchProviderInterface {
            $id = (string) $this->config->get('search_provider', 'sql');
            try { return new \Nicode\FormStudio\Search\SelectedSearch($c->get(\Nicode\FormStudio\Registry\SearchProviderRegistry::class), $id, new \Nicode\FormStudio\Search\CursorCodec($this->key('submission-search-cursor'))); }
            catch (\Throwable) {
                try { $c->get(\Nicode\FormStudio\Infrastructure\Database\TechnicalLog::class)->record('ERROR', 'search.unavailable'); } catch (\Throwable) {}
                return new \Nicode\FormStudio\Search\UnavailableSearch($id);
            }
        });
        $container->share(\Nicode\FormStudio\Application\SubmissionReader::class, static fn (Container $c) => new \Nicode\FormStudio\Application\SubmissionReader($c->get(SubmissionRepository::class), $c->get(FormRepository::class), $c->get(Connection::class), $c->get(Authorization::class)->allows(...)));
        $container->share(\Nicode\FormStudio\Application\SubmissionAdministration::class, static fn (Container $c) => new \Nicode\FormStudio\Application\SubmissionAdministration($c->get(Connection::class), $c->get(Authorization::class)->allows(...)));
        $container->share(\Nicode\FormStudio\Application\SavedSubmissionViews::class, static fn (Container $c) => new \Nicode\FormStudio\Application\SavedSubmissionViews($c->get(Connection::class), $c->get(\Nicode\FormStudio\Application\SubmissionExplorer::class), $c->get(Authorization::class)->allows(...)));
        $container->share(\Nicode\FormStudio\Application\FileDownloads::class, static fn (Container $c) => new \Nicode\FormStudio\Application\FileDownloads($c->get(Connection::class), $c->get(FormRepository::class), $c->get(StorageProviderRegistry::class), $c->get(Authorization::class)->allows(...)));
        $container->share(\Nicode\FormStudio\Application\SubmissionExplorer::class, static fn (Container $c) => new \Nicode\FormStudio\Application\SubmissionExplorer($c->get(Connection::class), $c->get(FormRepository::class), $c->get(SearchProviderInterface::class), $c->get(\Nicode\FormStudio\Application\SubmissionReader::class), $c->get(Authorization::class)->allows(...), $c->get(Authorization::class)->submissionCapabilities(...), $c->get(FieldTypeRegistry::class)));
        $container->share(ActionRunRepository::class, static fn (Container $c) => new ActionRunRepository($c->get(Connection::class)));
        $container->share(ActionEngine::class, static fn (Container $c) => new ActionEngine($c->get(ActionRegistry::class), $c->get(ActionRunRepository::class), $c->get(ConditionEvaluator::class), $c->get(FieldTypeRegistry::class), $c->get(\Nicode\FormStudio\Infrastructure\Database\TechnicalLog::class), $c->get(\Nicode\FormStudio\Registry\ProviderDependencies::class), $c->get(LifecycleEventsInterface::class)));
        $container->share(PostSubmit::class, fn (Container $c) => new PostSubmit($c->get(ConditionEvaluator::class), $c->get(FieldTypeRegistry::class), $c->get(TokenTemplate::class), $c->get(RedirectPolicy::class), RuntimeMessages::all($this->app->getLanguage())));
        $container->share(SubmissionPipeline::class, static function (Container $c): SubmissionPipeline {
            $storage = $c->get(StorageProviderRegistry::class);
            $cleanup = static fn (string $provider, string $key): int => $c->get(\Nicode\FormStudio\Infrastructure\Database\JobRepository::class)->enqueue('file-cleanup', ['objects' => [['provider' => $provider, 'storage_key' => $key]]], 0);
            $journal = $c->get(\Nicode\FormStudio\Infrastructure\Database\UploadJournal::class);
            $gateway = $storage->has('local') && ($storage->get('local')->metadata()['available'] ?? true) ? new HttpUploadGateway(new UploadInspector(), $storage->get('local'), $cleanup, $journal) : null;
            return new SubmissionPipeline($c->get(FormRepository::class), $c->get(SubmissionRepository::class), $c->get(PublicAccess::class), $c->get(AttemptTokens::class), $c->get(CaptchaAdapterInterface::class), new RateLimiter($c->get(Connection::class)), $c->get(ValidationEngine::class), $c->get(ActionEngine::class), $c->get(PostSubmit::class), $storage, $gateway, static fn (string $event, string $correlation) => $c->get(\Nicode\FormStudio\Infrastructure\Database\TechnicalLog::class)->record('ERROR', $event, $correlation), $c->get(\Nicode\FormStudio\Registry\ProviderDependencies::class), $c->get(LifecycleEventsInterface::class), $cleanup, $journal);
        });
    }

    private function key(string $purpose): string
    {
        $secret = (string) $this->app->get('secret');
        // Joomla's installer generates a 16-character application secret.
        // HKDF separates purposes and returns the 32-byte keys our contracts use.
        if (strlen($secret) < 16) { throw new \DomainException('Joomla application secret is unavailable.'); }
        return hash_hkdf('sha256', $secret, 32, 'nicode-formstudio:' . $purpose);
    }

    private function hosts(string $key): array
    {
        $value = $this->config->get($key, []);
        if (is_string($value)) { $value = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY); }
        if (!is_array($value)) { throw new \DomainException('Invalid destination host policy.'); }
        foreach ($value as $host) { if (!is_string($host)) { throw new \DomainException('Invalid destination host policy.'); } }
        return array_values(array_unique(array_map(strtolower(...), $value)));
    }
}
