<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Application;

use Nicode\FormStudio\Actions\ActionContext;
use Nicode\FormStudio\Actions\ActionEngine;
use Nicode\FormStudio\Contract\CaptchaAdapterInterface;
use Nicode\FormStudio\Contract\RateLimiterInterface;
use Nicode\FormStudio\Domain\Uuid;
use Nicode\FormStudio\Field\FileFieldType;
use Nicode\FormStudio\Infrastructure\Database\FormRepository;
use Nicode\FormStudio\Infrastructure\Database\SubmissionRepository;
use Nicode\FormStudio\Registry\StorageProviderRegistry;
use Nicode\FormStudio\Security\AttemptTokens;
use Nicode\FormStudio\Security\CaptchaException;
use Nicode\FormStudio\Security\CaptchaPolicy;
use Nicode\FormStudio\Security\PublicAccess;
use Nicode\FormStudio\Storage\HttpUploadGateway;
use Nicode\FormStudio\Submission\PostSubmit;
use Nicode\FormStudio\Submission\RequestContext;
use Nicode\FormStudio\Submission\SubmissionFailure;
use Nicode\FormStudio\Submission\SubmitRequest;
use Nicode\FormStudio\Validation\ValidationEngine;

/** AJAX and full-page controllers use this same application service. */
final readonly class SubmissionPipeline
{
    public function __construct(private FormRepository $forms, private SubmissionRepository $submissions, private PublicAccess $access, private AttemptTokens $attempts, private CaptchaAdapterInterface $captcha, private RateLimiterInterface $limiter, private ValidationEngine $validation, private ActionEngine $actions, private PostSubmit $postSubmit, private StorageProviderRegistry $storage, private ?HttpUploadGateway $uploads = null, private ?\Closure $safeFailureLog = null, private ?\Nicode\FormStudio\Registry\ProviderDependencies $dependencies = null, private ?\Nicode\FormStudio\Contract\LifecycleEventsInterface $events = null, private ?\Closure $queueCleanup = null, private ?\Nicode\FormStudio\Infrastructure\Database\UploadJournal $uploadJournal = null) {}
    public function submit(SubmitRequest $request, RequestContext $context): array
    {
        return $this->process($request, $context);
    }
    public function submitInstances(\Nicode\FormStudio\Submission\RepeatedSubmitRequest $request, RequestContext $context): array
    {
        return $this->process($request->request, $context, $request->instances->declarations());
    }
    private function process(SubmitRequest $request, RequestContext $context, ?array $declarations = null): array
    {
        $staged = []; $owned = []; $correlation = Uuid::create();
        try {
            try { $form = $this->forms->get($request->formId); $this->access->assert($form, $context->viewLevels, $context->language, time()); }
            catch (\OutOfBoundsException) { throw new SubmissionFailure('form_unavailable'); }
            if ((int) $form['published_version_id'] !== $request->versionId) { throw new SubmissionFailure('form_unavailable'); }
            $spec = $this->forms->version($request->formId, $request->versionId); $definition = $spec->toArray(); $security = $definition['security'] ?? [];
            $localized = \Nicode\FormStudio\Translation\DefinitionTranslations::spec($spec, $context->language);
            if (!$context->csrfValid) { throw new SubmissionFailure('session_error'); }
            $fields = $localized->toArray()['fields'];
            if ($declarations !== null) {
                try {
                    $instances = new \Nicode\FormStudio\Domain\RepeatedInstances($definition['elements'], $declarations);
                    $instances->bind($request->values); $instances->bind($request->files);
                    $byUuid = array_column($fields, null, 'uuid'); $fields = [];
                    foreach ($instances->addresses() as $address) { $field = $byUuid[$address->field]; $field['uuid'] = $address->key(); $fields[] = $field; }
                } catch (\InvalidArgumentException) { throw new SubmissionFailure('validation_error'); }
            } elseif (in_array('repeatable-group', array_column($definition['elements'], 'type'), true)) { throw new SubmissionFailure('validation_error'); }
            $this->dependencies?->assert($spec);
            $persistenceMode = $definition['persistence']['mode'] ?? 'full';
            try { $attempt = $this->attempts->verify($request->attempt, $request->formId, $request->versionId, $context->sessionBinding . ':' . $context->channel, $security['minimum_seconds'] ?? 0, $security['attempt_lifetime'] ?? 7200); }
            catch (\DomainException) { throw new SubmissionFailure('session_error'); }
            if (($security['honeypot'] ?? true) && $request->honeypot !== '') { throw new SubmissionFailure('anti_spam_rejected'); }
            $rate = $this->limiter->consume(hash('sha256', $request->formId . ':' . $context->rateScope), $security['rate_limit'] ?? 30, $security['rate_window'] ?? 60);
            if (!$rate->allowed) { throw new SubmissionFailure('rate_limited', retryAfter: $rate->retryAfter); }
            $trusted = $context->trustedValues;
            // Presence-only receipts are server-derived from genuine upload temp
            // files; this lets empty/not_empty rules activate dependent file fields.
            foreach ($fields as $field) {
                if (!in_array($field['type'], ['file', 'multiple-files'], true)) { continue; }
                $presence = [];
                foreach ($request->files[$field['uuid']] ?? [] as $upload) {
                    if (is_string($upload['tmp_name'] ?? null) && is_uploaded_file($upload['tmp_name'])) { $presence[] = ['uuid' => Uuid::create(), 'name' => '', 'mime' => '', 'size' => 0]; }
                }
                $trusted[$field['uuid']] = $field['type'] === 'file' ? ($presence[0] ?? null) : $presence;
            }
            $preliminary = $declarations === null ? $this->validation->validate($localized, $request->values, $trusted, $context->ruleContext()) : $this->validation->validateInstances($localized, $declarations, $request->values, $trusted, $context->ruleContext());
            foreach ($fields as $field) {
                $uuid = $field['uuid'];
                if (!in_array($field['type'], ['file', 'multiple-files'], true)) { continue; }
                $trusted[$uuid] = $field['type'] === 'file' ? null : [];
                if (!$preliminary->rules->states[$uuid]['active'] || ($request->files[$uuid] ?? []) === []) { continue; }
                if ($this->uploads === null) { $this->log('storage.unavailable', $correlation); throw new SubmissionFailure('upload_error', [$uuid => ['upload_unavailable']]); }
                try { $received = $this->uploads->receive($request->files[$uuid], FileFieldType::policy($field['config'], $field['type'] === 'multiple-files'), $request->formId); }
                catch (\InvalidArgumentException|\LengthException) { throw new SubmissionFailure('upload_error', [$uuid => ['upload_invalid']]); }
                catch (\Throwable $error) { $this->log('storage.unavailable', $correlation); throw $error; }
                foreach ($received as $entry) { $staged[] = ['field_uuid' => $uuid, 'field_address' => $uuid, ...$entry]; }
                $receipts = array_column($received, 'receipt'); $trusted[$uuid] = $field['type'] === 'file' ? ($receipts[0] ?? null) : $receipts;
            }
            $eventContext = ['form_uuid' => $definition['uuid'], 'form_id' => $request->formId, 'version_id' => $request->versionId, 'channel' => $context->channel, 'locale' => $context->language];
            $this->events?->emit('BeforeValidation', $eventContext);
            $validated = $declarations === null ? $this->validation->validate($localized, $request->values, $trusted, $context->ruleContext()) : $this->validation->validateInstances($localized, $declarations, $request->values, $trusted, $context->ruleContext());
            $this->events?->emit('AfterValidation', $eventContext + ['valid' => $validated->valid(), 'error_count' => count($validated->errors)]);
            if (!$validated->valid()) { throw new SubmissionFailure('validation_error', $validated->errors); }
            $activeFiles = array_values(array_filter($staged, static fn (array $entry): bool => array_key_exists($entry['field_uuid'], $validated->values)));
            $labels = [];
            foreach ($fields as $field) {
                $uuid = $field['uuid']; if (!array_key_exists($uuid, $validated->values) || $validated->rules->states[$uuid]['options'] === []) { continue; }
                $map = array_column($validated->rules->states[$uuid]['options'], 'label', 'value'); $value = $validated->values[$uuid];
                $labels[$uuid] = is_array($value) ? implode(', ', array_map(static fn ($item): string => $map[$item] ?? '', $value)) : ($map[$value] ?? '');
            }
            $persistence = ['channel' => $context->channel, 'locale' => $context->language, 'user_id' => ($definition['privacy']['store_user'] ?? false) && $context->userId > 0 ? $context->userId : null, 'option_labels' => $labels, 'files' => $activeFiles];
            if ($persistenceMode === 'none') { $persistence['user_id'] = null; }
            $retention = $definition['privacy']['retention'] ?? [];
            $persistence['expires_at'] = (new \Nicode\FormStudio\Privacy\RetentionPolicy($retention['action'] ?? 'indefinite', $retention['amount'] ?? 0, $retention['unit'] ?? 'days'))->expiresAt(time());
            try { $replay = $declarations === null ? $this->submissions->findReplay($request->formId, $request->versionId, $spec, $validated->values, $attempt, $persistence) : $this->submissions->findReplayInstances($request->formId, $request->versionId, $spec, $declarations, $validated, $attempt, $persistence); }
            catch (\DomainException) { throw new SubmissionFailure('session_error'); }
            if ($replay !== null && ($response = $this->submissions->response($request->formId, $attempt)) !== null) { return $response + ['replayed' => true]; }
            if ($replay === null) {
                $captcha = $security['captcha'] ?? [];
                try { $this->captcha->validate(new CaptchaPolicy($captcha['mode'] ?? 'inherit', $captcha['provider'] ?? null), $request->captchaAnswer); }
                catch (CaptchaException $error) { throw new SubmissionFailure($error->category); }
            }
            if ($replay === null) {
                $this->events?->emit('BeforeSubmissionPersist', $eventContext);
                $persistence['request_metadata'] = $context->requestMetadata($definition['privacy'] ?? [], $persistenceMode);
            }
            try { $submission = $replay ?? ($declarations === null ? $this->submissions->persist($request->formId, $request->versionId, $spec, $validated->values, $attempt, $persistence) : $this->submissions->persistInstances($request->formId, $request->versionId, $spec, $declarations, $validated, $attempt, $persistence)); }
            catch (\Throwable) { throw new SubmissionFailure('persistence_error'); }
            if (!$submission->replayed && $persistenceMode === 'full') {
                $persistedFields = array_column(array_filter($fields, static fn (array $field): bool => $field['persist'] ?? true), 'uuid');
                foreach ($activeFiles as $entry) { if (in_array($entry['field_uuid'], $persistedFields, true)) { $owned[$entry['file']->key] = true; } }
            }
            $row = $this->submissions->get($request->formId, $submission->id); $storedPayload = json_decode($row['canonical_payload'], true, 512, JSON_THROW_ON_ERROR);
            if (!$submission->replayed) { $this->events?->emit('AfterSubmissionPersist', $eventContext + ['submission_uuid' => $submission->uuid, 'replayed' => false]); }
            $values = array_replace($validated->values, $storedPayload['values']);
            $activeInstances = null;
            if ($declarations !== null) { $activeInstances = array_filter($instances->declarations(), static fn (string $key): bool => $validated->rules->states[$key]['active'], ARRAY_FILTER_USE_KEY); }
            $attachments = $submission->replayed ? null : new StagedMailAttachments($this->storage, $spec, $submission->uuid, $values, $activeInstances, $activeFiles);
            $actionContext = new ActionContext($spec, $values, $submission->uuid, $row['received_at'], array_replace($labels, $storedPayload['option_labels'] ?? []), locale: $row['locale'], instances: $activeInstances, attachments: $attachments);
            $actionResult = $this->actions->execute($submission->id, $actionContext);
            $response = $this->postSubmit->result($actionContext, $actionResult);
            if (in_array($actionResult['status'], ['succeeded', 'partial_failure'], true)) { $response['next_attempt'] = $this->attempts->issue($request->formId, $request->versionId, $context->sessionBinding . ':' . $context->channel); }
            if ($actionResult['status'] !== 'pending') { $this->submissions->completeAttempt($request->formId, $attempt, $response, $persistenceMode === 'none'); }
            return $response;
        } catch (SubmissionFailure $error) {
            if ($error->category === 'persistence_error') { $this->log('submission.persistence_failed', $correlation); }
            $response = ['accepted' => false, 'category' => $error->category, 'errors' => $error->errors, 'retry_after' => $error->retryAfter, 'correlation' => $correlation];
            if (isset($localized)) {
                $presentation = $localized->toArray();
                $customMessages = \Nicode\FormStudio\Translation\DefinitionTranslations::messages($presentation);
                if (isset($customMessages[$error->category])) { $response['message'] = $customMessages[$error->category]; }
                foreach ($fields ?? $presentation['fields'] as $field) { if (isset($error->errors[$field['uuid']])) { $response['error_messages'][$field['uuid']] = array_intersect_key($field['config']['validation_messages'] ?? [], array_flip($error->errors[$field['uuid']])); } }
            }
            return $response;
        } catch (\Throwable) {
            $this->log('submission.unexpected', $correlation);
            $response = ['accepted' => false, 'category' => 'unexpected_error', 'errors' => [], 'correlation' => $correlation];
            if (isset($localized)) { $messages = \Nicode\FormStudio\Translation\DefinitionTranslations::messages($localized->toArray()); if (isset($messages['unexpected_error'])) { $response['message'] = $messages['unexpected_error']; } }
            return $response;
        } finally {
            foreach ($staged as $entry) {
                if (isset($owned[$entry['file']->key])) { continue; }
                try {
                    if ($this->uploadJournal !== null) { $this->uploadJournal->discardKey($entry['file']->provider, $entry['file']->key, $entry['staging_token'], $this->storage->get($entry['file']->provider)); }
                    elseif (!\Nicode\FormStudio\Storage\UploadCleanup::discard($this->storage->get($entry['file']->provider), $entry['file']->key, $this->queueCleanup)) { $this->log('upload.cleanup_failed', $correlation); }
                } catch (\Throwable) { $this->log('upload.cleanup_failed', $correlation); }
            }
        }
    }
    private function log(string $event, string $correlation): void
    {
        try { if ($this->safeFailureLog !== null) { ($this->safeFailureLog)($event, $correlation); } }
        catch (\Throwable) { /* Logging failure must not replace the safe response. */ }
    }
}
