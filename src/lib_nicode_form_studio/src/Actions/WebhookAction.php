<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Actions;

use Nicode\FormStudio\Contract\ActionInterface;
use Nicode\FormStudio\Contract\HttpTransportInterface;
use Nicode\FormStudio\Contract\SecretStoreInterface;
use Nicode\FormStudio\Domain\CanonicalJson;
use Nicode\FormStudio\Domain\Diagnostic;
use Nicode\FormStudio\Http\DestinationPolicy;

final readonly class WebhookAction implements ActionInterface
{
    public function __construct(private HttpTransportInterface $http, private DestinationPolicy $policy, private SecretStoreInterface $secrets, private TokenTemplate $templates) {}
    public function id(): string { return 'webhook'; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array
    {
        return ['id' => $this->id(), 'version' => $this->version(), 'failure_policies' => ['blocking', 'non_blocking'], 'retry' => 'definite_failure_only', 'configuration_schema' => ['type' => 'object', 'properties' => [
            'url' => ['type' => 'string'], 'method' => ['type' => 'string', 'enum' => ['POST', 'PUT', 'PATCH']],
            'timeout' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 30],
            'payload' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']], 'headers' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            'signing_secret' => ['type' => 'string', 'secret_reference' => true], 'bearer_secret' => ['type' => 'string', 'secret_reference' => true],
        ]]];
    }
    public function validateConfiguration(array $configuration, string $path): array
    {
        $errors = [];
        try { $this->policy->host(is_string($configuration['url'] ?? null) ? $configuration['url'] : ''); }
        catch (\DomainException) { $errors[] = new Diagnostic('action.webhook_url', $path . '/url', 'Use an allowed HTTPS destination.'); }
        if (!in_array($configuration['method'] ?? 'POST', ['POST', 'PUT', 'PATCH'], true)) { $errors[] = new Diagnostic('action.webhook_method', $path, 'Unsupported webhook method.'); }
        if (!is_int($configuration['timeout'] ?? 10) || ($configuration['timeout'] ?? 10) < 1 || ($configuration['timeout'] ?? 10) > 30) { $errors[] = new Diagnostic('action.webhook_timeout', $path, 'Timeout must be 1 to 30 seconds.'); }
        foreach (['payload', 'headers'] as $key) {
            if (!is_array($configuration[$key] ?? [])) { $errors[] = new Diagnostic('action.webhook_map', "$path/$key", 'Expected a mapping.'); continue; }
            $headerNames = [];
            foreach ($configuration[$key] ?? [] as $name => $value) {
                if (!is_string($name) || !is_string($value) || strlen($value) > 65536 || ($key === 'headers' && (preg_match('/^X-[A-Za-z0-9-]{1,60}$/D', $name) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value)))) { $errors[] = new Diagnostic('action.webhook_map', "$path/$key", 'Invalid payload or safe custom header mapping.'); }
                if ($key === 'headers' && is_string($name)) {
                    $normalized = strtolower($name);
                    if (isset($headerNames[$normalized]) || in_array($normalized, ['x-formstudio-signature', 'x-formstudio-timestamp'], true)) {
                        $errors[] = new Diagnostic('action.webhook_header_identity', "$path/headers", 'Custom header names must be unique ignoring case and cannot replace signing headers.');
                    }
                    $headerNames[$normalized] = true;
                }
            }
        }
        foreach (['signing_secret', 'bearer_secret'] as $key) {
            if (isset($configuration[$key]) && (!is_string($configuration[$key]) || preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{0,127}$/D', $configuration[$key]) !== 1)) { $errors[] = new Diagnostic('action.secret_reference', "$path/$key", 'Expected a server secret reference.'); }
        }
        return $errors;
    }
    public function execute(array $configuration, ActionContext $context): ActionOutcome
    {
        if ($this->validateConfiguration($configuration, '/action') !== []) { throw new ActionFailure('configuration_invalid'); }
        try {
            $tokens = $context->emailTokens(); $payload = []; $headers = [];
            $encodedBytes = 2;
            foreach ($configuration['payload'] ?? [] as $key => $template) {
                $overhead = strlen(CanonicalJson::encode($key)) + 1 + ($payload === [] ? 0 : 1);
                $remaining = 1048576 - $encodedBytes - $overhead;
                if ($remaining < 2) { throw new ActionFailure('webhook_request_limit'); }
                $value = $this->templates->render($template, $tokens, maximumBytes: $remaining - 2);
                $encodedBytes += $overhead + strlen(CanonicalJson::encode($value));
                if ($encodedBytes > 1048576) { throw new ActionFailure('webhook_request_limit'); }
                $payload[$key] = $value;
            }
            foreach ($configuration['headers'] ?? [] as $key => $template) {
                $headers[$key] = $this->templates->render($template, $tokens, 'header', 8192);
                if (strlen($headers[$key]) > 8192) { throw new ActionFailure('webhook_request_limit'); }
            }
            $body = CanonicalJson::encode($payload); $timestamp = (string) time();
            if (strlen($body) > 1048576) { throw new ActionFailure('webhook_request_limit'); }
            $headers['Content-Type'] = 'application/json'; $headers['Idempotency-Key'] = hash('sha256', $context->reference . ':' . ($context->actionUuid ?? throw new ActionFailure('action_identity_missing')));
        } catch (ActionFailure $failure) { throw $failure; }
        catch (\LengthException) { throw new ActionFailure('webhook_request_limit'); }
        catch (\Throwable) { throw new ActionFailure('webhook_preparation_failed'); }
        try {
            if (isset($configuration['bearer_secret'])) {
                $bearer = $this->secrets->get($configuration['bearer_secret']);
                if ($bearer === '' || strlen($bearer) > 8185 || preg_match('/[\x00-\x20\x7f]/', $bearer)) { throw new \InvalidArgumentException('Invalid bearer secret.'); }
                $headers['Authorization'] = 'Bearer ' . $bearer;
            }
            if (isset($configuration['signing_secret'])) { $headers['X-FormStudio-Timestamp'] = $timestamp; $headers['X-FormStudio-Signature'] = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $this->secrets->get($configuration['signing_secret'])); }
        } catch (\Throwable) { throw new ActionFailure('secret_unavailable'); }
        $response = $this->http->request($configuration['url'], $configuration['method'] ?? 'POST', $headers, $body, $configuration['timeout'] ?? 10);
        if ($response->status < 200 || $response->status >= 300) { throw new ActionFailure('webhook_http_rejected', $response->status >= 500); }
        return new ActionOutcome('webhook_delivered');
    }
}
