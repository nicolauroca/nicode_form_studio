<?php
declare(strict_types=1);

// Focused integration on the existing isolated MariaDB; transport captures only in memory.
define('_JEXEC', 1); $root = dirname(__DIR__);
require $root . '/build/joomla-6.0.0/libraries/vendor/autoload.php';
require $root . '/src/lib_nicode_form_studio/autoload.php';
$configuration = json_decode(file_get_contents($root . '/build/database-test.json'), true, flags: JSON_THROW_ON_ERROR);
if ($configuration['host'] !== '127.0.0.1' || $configuration['port'] !== 13367 || $configuration['database'] !== 'formstudio_test') { throw new RuntimeException('Refusing non-isolated webhook test.'); }
$driver = (new Joomla\Database\DatabaseFactory())->getDriver('mysql', ['host' => '127.0.0.1', 'port' => 13367, 'user' => $configuration['user'], 'password' => $configuration['password'], 'database' => 'formstudio_test', 'prefix' => 'nfs_', 'charset' => 'utf8mb4']);
$db = new Nicode\FormStudio\Infrastructure\Database\Connection($driver);
$fields = new Nicode\FormStudio\Registry\FieldTypeRegistry(); Nicode\FormStudio\Field\CoreFieldTypes::register($fields);
$http = new class implements Nicode\FormStudio\Contract\HttpTransportInterface {
    public int $status = 204; public array $requests = [];
    public function request(string $url, string $method, array $headers, string $body, int $timeout = 10): Nicode\FormStudio\Http\HttpResponse {
        $this->requests[] = compact('url', 'method', 'headers', 'body', 'timeout');
        return new Nicode\FormStudio\Http\HttpResponse($this->status, '');
    }
};
$secrets = new class implements Nicode\FormStudio\Contract\SecretStoreInterface {
    public function get(string $reference): string { return match ($reference) { 'fixture.signing' => 'synthetic-signature', 'fixture.bearer' => 'synthetic-bearer', default => throw new RuntimeException('Unexpected secret reference.') }; }
};
$actions = new Nicode\FormStudio\Registry\ActionRegistry();
$actions->register(new Nicode\FormStudio\Actions\WebhookAction($http, new Nicode\FormStudio\Http\DestinationPolicy(['hooks.example.test']), $secrets, new Nicode\FormStudio\Actions\TokenTemplate()));
$compiler = new Nicode\FormStudio\Compiler\FormCompiler($fields, $actions, new Nicode\FormStudio\Registry\ProviderRegistry(), new Nicode\FormStudio\Registry\ProviderRegistry());
$forms = new Nicode\FormStudio\Infrastructure\Database\FormRepository($db, $compiler);
$submissions = new Nicode\FormStudio\Infrastructure\Database\SubmissionRepository($db, new Nicode\FormStudio\Search\IndexProjector($fields), str_repeat('webhook-fixture-', 3));
$runs = new Nicode\FormStudio\Infrastructure\Database\ActionRunRepository($db);
$engine = new Nicode\FormStudio\Actions\ActionEngine($actions, $runs, new Nicode\FormStudio\Rules\ConditionEvaluator(Nicode\FormStudio\Registry\RuleOperatorRegistry::core()), $fields);
$assert = static function (bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$id = $forms->create('Webhook integration', 'webhook-' . bin2hex(random_bytes(6)), 1);
try {
    $draft = $forms->draft($id); $field = Nicode\FormStudio\Domain\Uuid::create(); $action = Nicode\FormStudio\Domain\Uuid::create();
    $draft['elements'] = [['uuid' => $field, 'type' => 'field']];
    $draft['fields'] = [['uuid' => $field, 'type' => 'text', 'name' => 'answer', 'config' => []]];
    $draft['actions'] = [['uuid' => $action, 'type' => 'webhook', 'failure_policy' => 'non_blocking', 'config' => ['url' => 'https://hooks.example.test/receive', 'payload' => ['answer' => '{{field.' . $field . '.value}}'], 'bearer_secret' => 'fixture.bearer', 'signing_secret' => 'fixture.signing']]];
    $revision = $forms->saveDraft($id, 0, $draft, 1); $version = $forms->publish($id, $revision, 1); $spec = $forms->version($id, $version);
    $results = [];
    foreach ([204 => 'succeeded', 400 => 'failed', 503 => 'unknown'] as $status => $state) {
        $http->status = $status; $value = '<b>"synthetic"</b>';
        $submission = $submissions->persist($id, $version, $spec, [$field => $value], hash('sha256', random_bytes(32)));
        $context = new Nicode\FormStudio\Actions\ActionContext($spec, [$field => $value], $submission->uuid, gmdate(DATE_ATOM));
        $before = count($http->requests); $engine->execute($submission->id, $context);
        $assert($runs->latest($submission->id, $action)['state'] === $state && count($http->requests) === $before + 1, 'Webhook execution state not persisted.');
        $sent = $http->requests[$before]; $headers = $sent['headers'];
        $assert(json_decode($sent['body'], true, flags: JSON_THROW_ON_ERROR) === ['answer' => $value] && $headers['Authorization'] === 'Bearer synthetic-bearer', 'Webhook mapping or server secret failed.');
        $assert(hash_equals('sha256=' . hash_hmac('sha256', $headers['X-FormStudio-Timestamp'] . '.' . $sent['body'], 'synthetic-signature'), $headers['X-FormStudio-Signature']), 'Webhook signature failed.');
        $engine->execute($submission->id, $context); $assert(count($http->requests) === $before + 1, 'Replay delivered the webhook again.');
        $http->status = 204; $engine->execute($submission->id, $context, true);
        $assert(count($http->requests) === $before + ($state === 'failed' ? 2 : 1), 'Retry repeated a succeeded or uncertain webhook.');
        if ($state === 'failed') { $assert($http->requests[$before + 1]['headers']['Idempotency-Key'] === $headers['Idempotency-Key'] && $runs->latest($submission->id, $action)['state'] === 'succeeded', 'Definite retry lost its identity.'); }
        $results[] = $state;
    }
    file_put_contents($root . '/build/webhook-integration-results.json', json_encode(['passed' => true, 'form_id' => $id, 'states' => $results, 'external_delivery' => false, 'timestamp' => gmdate(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "Webhook integration passed: publication, canonical mapping, server secrets/signature, stored outcomes, replay and definite-only retry. Transport captured in memory.\n";
} finally { $forms->deactivate($id, (int) $forms->get($id)['draft_revision'], 1, 'unpublished'); }
