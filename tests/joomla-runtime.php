<?php
declare(strict_types=1);

$root = dirname(__DIR__); $site = $root . '/build/joomla-6.0.0';
define('_JEXEC', 1); define('JPATH_BASE', $site);
require $site . '/includes/defines.php'; require $site . '/includes/framework.php';
require $root . '/src/lib_nicode_form_studio/autoload.php';
$container = Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.cli')->alias(Joomla\CMS\Session\Session::class, 'session.cli')->alias(Joomla\Session\SessionInterface::class, 'session.cli');
$app = $container->get(Joomla\CMS\Application\SiteApplication::class); Joomla\CMS\Factory::$application = $app;
$app->createExtensionNamespaceMap();
$language = $container->get(Joomla\CMS\Language\LanguageFactoryInterface::class)->createLanguage('en-GB', false);
$language->load('com_nicode_form_studio', $root . '/src/com_nicode_form_studio/site');
$app->loadLanguage($language);
if ($app->get('db') !== 'formstudio_joomla' || $app->get('host') !== '127.0.0.1:13367') { throw new RuntimeException('Refusing non-isolated Joomla runtime test.'); }
$runtime = new Joomla\DI\Container($container);
$runtime->registerServiceProvider(new Nicode\FormStudio\Infrastructure\Joomla\RuntimeProvider($app, new Joomla\Registry\Registry(['captcha_mode' => 'none']), $site));
$users = $container->get(Joomla\CMS\User\UserFactoryInterface::class);
$credentials = json_decode(file_get_contents($root . '/build/joomla-test.json'), true, 512, JSON_THROW_ON_ERROR);
$admin = $users->loadUserByUsername($credentials['username']); $app->loadIdentity($admin);
$administration = $runtime->get(Nicode\FormStudio\Application\FormAdministration::class);
$id = $administration->create('Native composition fixture', 'native-' . bin2hex(random_bytes(5)), (int) $admin->id);
$draft = $administration->edit($id, (int) $admin->id)['draft']; $uuid = Nicode\FormStudio\Domain\Uuid::create();
$draft['elements'] = [['uuid' => $uuid, 'type' => 'field', 'parent_uuid' => null]];
$draft['fields'] = [['uuid' => $uuid, 'type' => 'text', 'name' => 'answer', 'index' => true, 'config' => ['label' => 'Your answer', 'required' => true, 'max_length' => 255]]];
$secretUuid = Nicode\FormStudio\Domain\Uuid::create();
$draft['elements'][] = ['uuid' => $secretUuid, 'type' => 'field', 'parent_uuid' => null];
$draft['fields'][] = ['uuid' => $secretUuid, 'type' => 'text', 'name' => 'restricted', 'sensitive' => true, 'config' => ['label' => 'Restricted test answer']];
$revision = $administration->save($id, 0, $draft, (int) $admin->id);
$version = $administration->publish($id, $revision, (int) $admin->id);
$preview = $runtime->get(Nicode\FormStudio\Application\FormPreview::class)->render($id, (int) $admin->id);
if (!str_contains($preview['html'] ?? '', 'data-nfs-preview="true"') || !str_contains($preview['html'], 'data-nfs-submit disabled')) { throw new RuntimeException('Draft preview permits submission.'); }
try { $runtime->get(Nicode\FormStudio\Application\FormPreview::class)->render($id, 0); throw new RuntimeException('Guest previewed a draft.'); } catch (DomainException) {}
$listSecond = $administration->create('Native composition second', 'native-second-' . bin2hex(random_bytes(5)), (int) $admin->id);
$orphan = $runtime->get(Nicode\FormStudio\Infrastructure\Database\FormRepository::class)->create('Native composition orphan', 'native-orphan-' . bin2hex(random_bytes(5)), (int) $admin->id);
$listing = $runtime->get(Nicode\FormStudio\Application\FormListing::class);
$page = $listing->page((int) $admin->id, ['search' => 'Native composition'], limit: 1);
if (count($page['rows']) !== 1 || $page['rows'][0]['id'] !== $listSecond || $page['next_cursor'] === null) { throw new RuntimeException('Native form listing ACL or pagination failed.'); }
$nextPage = $listing->page((int) $admin->id, ['search' => 'Native composition'], $page['next_cursor'], 1);
if ($nextPage['rows'][0]['id'] !== $id || $listing->page((int) $admin->id, ['search' => "Native composition' OR 1=1"])['rows'] !== []) { throw new RuntimeException('Native form listing cursor or bound search failed.'); }
if ($page['rows'][0]['field_count'] !== 0 || $page['rows'][0]['submission_count'] !== 0 || $nextPage['rows'][0]['field_count'] !== 2) { throw new RuntimeException('Form listing counts did not match the saved draft.'); }
try { $listing->page((int) $admin->id, ['search' => 'different'], $page['next_cursor']); throw new RuntimeException('Query-mismatched form cursor accepted.'); } catch (InvalidArgumentException) {}
try { $listing->page(0); throw new RuntimeException('Guest listed forms.'); } catch (DomainException) {}
$app->loadIdentity($users->loadUserById(0));
$context = new Nicode\FormStudio\Submission\RequestContext(0, [1], 'en-GB', 'native-composition-session', hash('sha256', random_bytes(32)), true);
$rendered = $runtime->get(Nicode\FormStudio\Application\FormDisplay::class)->render($id, $context, 'native-component', '/index.php?option=com_nicode_form_studio&task=form.submit', 'fixture_csrf');
preg_match('/name="attempt" value="([^"]+)"/', $rendered['html'], $token);
if (!isset($token[1]) || $rendered['version_id'] !== $version) { throw new RuntimeException('Native composition render failed.'); }
$request = new Nicode\FormStudio\Submission\SubmitRequest($id, $version, $token[1], [$uuid => 'Synthetic native composition answer', $secretUuid => 'Synthetic restricted answer']);
$pipeline = $runtime->get(Nicode\FormStudio\Application\SubmissionPipeline::class);
$result = $pipeline->submit($request, $context);
if (!$result['accepted'] || !$result['processed']) { throw new RuntimeException('Native composition submit failed: ' . ($result['category'] ?? 'unknown')); }
if ($result['message'] !== 'Your response has been received.') { throw new RuntimeException('Native language response failed.'); }
$replay = $pipeline->submit($request, $context);
if (!($replay['replayed'] ?? false) || $replay['reference'] !== $result['reference']) { throw new RuntimeException('Native composition replay failed.'); }
$explorer = $runtime->get(Nicode\FormStudio\Application\SubmissionExplorer::class);
$responses = $explorer->page((int) $admin->id, new Nicode\FormStudio\Search\SearchRequest(['form_id' => $id]));
if (count($responses['rows']) !== 1 || $responses['rows'][0]['uuid'] !== $result['reference']) { throw new RuntimeException('Native submission explorer failed.'); }
$responseId = (int) $responses['rows'][0]['id'];
$countedForms = $listing->page((int) $admin->id, ['search' => $administration->edit($id, (int) $admin->id)['form']['alias']]);
if ($countedForms['rows'][0]['submission_count'] !== 1) { throw new RuntimeException('Form listing counted an idempotent replay twice.'); }
$hiddenCounts = new Nicode\FormStudio\Application\FormListing($runtime->get(Nicode\FormStudio\Infrastructure\Database\Connection::class), new Nicode\FormStudio\Search\CursorCodec(str_repeat('fixture-count-key', 3)), static fn (): bool => true, static function (int $actor, array $rows): array {
    $result = []; foreach ($rows as $row) { $result[(int) $row['id']] = ['core.edit' => true, 'formstudio.submissions.view' => false]; } return $result;
});
$hiddenPage = $hiddenCounts->page((int) $admin->id, ['search' => $administration->edit($id, (int) $admin->id)['form']['alias']]);
if ($hiddenPage['rows'][0]['submission_count'] !== null || $hiddenPage['rows'][0]['field_count'] !== 2) { throw new RuntimeException('Form listing exposed a response count without response access.'); }
$detail = $explorer->detail((int) $admin->id, $id, $responseId);
if ($detail['values'][$uuid] !== 'Synthetic native composition answer' || $detail['form_version_id'] !== $version || isset($responses['rows'][0]['canonical_payload'])) { throw new RuntimeException('Submission explorer detail or header isolation failed.'); }
if (isset($detail['values'][$secretUuid]) || !in_array($secretUuid, $detail['masked'], true) || $explorer->detail((int) $admin->id, $id, $responseId, true)['values'][$secretUuid] !== 'Synthetic restricted answer') { throw new RuntimeException('Explorer reveal/masking failed.'); }
try { $explorer->detail((int) $admin->id, $listSecond, $responseId); throw new RuntimeException('Cross-form response read accepted.'); } catch (OutOfBoundsException) {}
try { $explorer->page(0, new Nicode\FormStudio\Search\SearchRequest()); throw new RuntimeException('Guest listed responses.'); } catch (DomainException) {}
$withColumns = $explorer->page((int) $admin->id, new Nicode\FormStudio\Search\SearchRequest(['form_id' => $id]), [$uuid]);
if ($withColumns['rows'][0]['cells'][$uuid]['value'] !== 'Synthetic native composition answer') { throw new RuntimeException('Selected answer column failed.'); }
try { $explorer->page((int) $admin->id, new Nicode\FormStudio\Search\SearchRequest(['form_id' => $id]), [$secretUuid]); throw new RuntimeException('Sensitive field offered as a default answer column.'); } catch (InvalidArgumentException) {}
$savedViews = $runtime->get(Nicode\FormStudio\Application\SavedSubmissionViews::class);
$savedQuery = ['filters' => ['form_id' => $id], 'fields' => [['field' => $uuid, 'operator' => 'contains', 'value' => 'Synthetic']], 'columns' => [$uuid]];
$savedId = $savedViews->create((int) $admin->id, 'Private native preset', $savedQuery);
if ($savedViews->read((int) $admin->id, $savedId)['query'] !== $savedQuery) { throw new RuntimeException('Saved query changed filters or columns.'); }
$ownerBoundary = new Nicode\FormStudio\Application\SavedSubmissionViews($runtime->get(Nicode\FormStudio\Infrastructure\Database\Connection::class), $explorer, static fn (): bool => true);
try { $ownerBoundary->read((int) $admin->id + 100000, $savedId); throw new RuntimeException('Private saved view exposed to another actor.'); } catch (OutOfBoundsException) {}
try { $ownerBoundary->remove((int) $admin->id + 100000, $savedId); throw new RuntimeException('Private saved view deleted by another actor.'); } catch (OutOfBoundsException) {}
$savedViews->remove((int) $admin->id, $savedId);
$historicalForm = $administration->create('Historical columns fixture', 'historical-columns-' . bin2hex(random_bytes(5)), (int) $admin->id);
$historicalDraft = $administration->edit($historicalForm, (int) $admin->id)['draft']; $historicalField = Nicode\FormStudio\Domain\Uuid::create();
$historicalDraft['elements'] = [['uuid' => $historicalField, 'type' => 'field']];
$historicalDraft['fields'] = [['uuid' => $historicalField, 'name' => 'historic', 'type' => 'text', 'sensitive' => true, 'config' => ['label' => 'Old private label']]];
$historicalRevision = $administration->save($historicalForm, 0, $historicalDraft, (int) $admin->id); $historicalPrivate = $administration->publish($historicalForm, $historicalRevision, (int) $admin->id);
$formRepository = $runtime->get(Nicode\FormStudio\Infrastructure\Database\FormRepository::class); $responseRepository = $runtime->get(Nicode\FormStudio\Infrastructure\Database\SubmissionRepository::class);
$historicalResponse = $responseRepository->persist($historicalForm, $historicalPrivate, $formRepository->version($historicalForm, $historicalPrivate), [$historicalField => 'Historical restricted value'], hash('sha256', random_bytes(32)));
$historicalDraft['fields'][0]['sensitive'] = false; $historicalDraft['fields'][0]['config']['label'] = 'Current public label';
$historicalRevision = $administration->save($historicalForm, (int) $formRepository->get($historicalForm)['draft_revision'], $historicalDraft, (int) $admin->id); $historicalPublic = $administration->publish($historicalForm, $historicalRevision, (int) $admin->id);
$responseRepository->persist($historicalForm, $historicalPublic, $formRepository->version($historicalForm, $historicalPublic), [$historicalField => 'Public value'], hash('sha256', random_bytes(32)));
$historicalColumns = $explorer->page((int) $admin->id, new Nicode\FormStudio\Search\SearchRequest(['form_id' => $historicalForm]), [$historicalField]);
$historicalRows = array_column($historicalColumns['rows'], null, 'id');
if (!$historicalRows[$historicalResponse->id]['cells'][$historicalField]['masked'] || $historicalRows[$historicalResponse->id]['cells'][$historicalField]['value'] !== null) { throw new RuntimeException('Current public column disclosed historically sensitive data.'); }
file_put_contents($root . '/build/joomla-runtime-results.json', json_encode(['passed' => true, 'joomla' => JVERSION, 'timestamp' => gmdate(DATE_ATOM), 'native_http_csrf_verified' => false, 'form_id' => $id, 'version_id' => $version, 'field_uuid' => $uuid, 'secret_uuid' => $secretUuid, 'submission_id' => $responseId], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Native Joomla DI composition: administrative create/save/publish, ACL form listing/cursors, public render, full submission and safe replay verified.\n";
