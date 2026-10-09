<?php
declare(strict_types=1);
namespace Nicode\Component\FormStudio\Site\Controller;
defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Nicode\FormStudio\Application\{FormDisplay, SubmissionPipeline};
use Nicode\FormStudio\Infrastructure\Database\FormRepository;
use Nicode\FormStudio\Infrastructure\Joomla\{RequestAdapter, RuntimeAssets, RuntimeMessages};
use Nicode\FormStudio\Security\PublicAccess;

final class FormController extends BaseController
{
    public function rows(): void
    {
        $this->app->allowCache(false);
        $this->app->setHeader('Cache-Control', 'private, no-store, max-age=0', true);
        $this->app->setHeader('X-Content-Type-Options', 'nosniff', true);
        $status = 200; $runtime = null; $envelope = null; $context = null;
        try {
            if (strtoupper($this->input->getMethod()) !== 'POST') { $this->app->setHeader('Allow', 'POST', true); $status = 405; throw new \InvalidArgumentException('POST required.'); }
            $runtime = $this->app->bootComponent('com_nicode_form_studio')->runtime($this->app);
            $channel = $this->input->post->get('channel', 'component', 'raw');
            if (!is_string($channel) || !in_array($channel, ['component', 'module'], true)) { throw new \InvalidArgumentException('Invalid channel.'); }
            $context = $runtime->get(RequestAdapter::class)->context($channel, true);
            if (!$context->csrfValid) { throw new \Nicode\FormStudio\Submission\SubmissionFailure('session_error'); }
            $ids = RequestAdapter::identities($this->input);
            $spec = $runtime->get(FormRepository::class)->version($ids['form_id'], $ids['version_id']);
            $envelope = RequestAdapter::requestInstances($this->input, $spec);
            $operation = $this->input->post->get('row_operation', null, 'raw');
            $group = $this->input->post->get('row_group', null, 'raw');
            $row = $this->input->post->get('row_id', null, 'raw');
            $button = $this->input->post->get('row_change', null, 'raw');
            if ($button !== null) {
                if (!is_string($button) || strlen($button) > 8192 || $operation !== null || $group !== null || $row !== null) { throw new \InvalidArgumentException('Ambiguous row operation.'); }
                try { $change = json_decode($button, true, 8, JSON_THROW_ON_ERROR); }
                catch (\JsonException) { throw new \InvalidArgumentException('Invalid row operation.'); }
                if (!is_array($change) || array_diff(array_keys($change), ['operation', 'group', 'row']) !== []) { throw new \InvalidArgumentException('Invalid row operation.'); }
                $operation = $change['operation'] ?? null; $group = $change['group'] ?? null; $row = $change['row'] ?? null;
            }
            if (!is_string($operation) || !is_string($group) || ($row !== null && !is_string($row))) { throw new \InvalidArgumentException('Invalid row operation.'); }
            $changed = $runtime->get(\Nicode\FormStudio\Application\FormRows::class)->change($envelope, $context, $operation, $group, $row);
            $instance = $this->input->post->get('instance', null, 'raw');
            if (!is_string($instance) || strlen($instance) > 128 || preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/D', $instance) !== 1) { throw new \InvalidArgumentException('Invalid render instance.'); }
            $rendered = $runtime->get(FormDisplay::class)->render($ids['form_id'], $context, $instance, Route::_('index.php?option=com_nicode_form_studio&task=form.submit', false), $this->app->getFormToken(), RuntimeMessages::all($this->app->getLanguage()), submitted: $changed['values'], attempt: $envelope->request->attempt, declarations: $changed['instances'], reset: true);
            $result = ['ok' => true, 'form' => $rendered];
        } catch (\Nicode\FormStudio\Submission\SubmissionFailure $error) {
            $result = ['ok' => false, 'error' => $error->category]; $status = $error->category === 'rate_limited' ? 429 : 403;
            if ($error->retryAfter > 0) { $this->app->setHeader('Retry-After', (string) $error->retryAfter, true); }
        } catch (\OutOfBoundsException) { $result = ['ok' => false, 'error' => 'form_unavailable']; $status = 404; }
        catch (\InvalidArgumentException) { $result = ['ok' => false, 'error' => 'validation_error']; if ($status !== 405) { $status = 422; } }
        catch (\Throwable) {
            $correlation = \Nicode\FormStudio\Domain\Uuid::create(); $status = 503; $result = ['ok' => false, 'error' => 'unexpected_error', 'correlation' => $correlation];
            try { $runtime?->get(\Nicode\FormStudio\Infrastructure\Database\TechnicalLog::class)->record('ERROR', 'submission.unexpected', $correlation); } catch (\Throwable) {}
        }
        $this->app->setHeader('Status', (string) $status, true);
        if ($this->input->getCmd('format', 'html') === 'json') {
            $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true); $this->app->sendHeaders();
            echo json_encode($result, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); $this->app->close(); return;
        }
        $rendered = $result['form'] ?? ['title' => '', 'html' => ''];
        if (!$result['ok'] && $envelope !== null && $context !== null && in_array($status, [422, 429], true)) {
            try {
                $request = $envelope->request;
                $rendered = $runtime->get(FormDisplay::class)->render($request->formId, $context, 'nfs-rows-' . bin2hex(random_bytes(6)), Route::_('index.php?option=com_nicode_form_studio&task=form.submit', false), $this->app->getFormToken(), RuntimeMessages::all($this->app->getLanguage()), submitted: $request->values, attempt: $request->attempt, declarations: $envelope->instances->declarations());
            } catch (\Throwable) {}
        }
        $rendered['result'] = ['message' => $this->app->getLanguage()->_('COM_NICODE_FORM_STUDIO_' . strtoupper($result['ok'] ? 'rows_updated' : $result['error']))];
        if ($rendered['html'] !== '') { RuntimeAssets::load($this->app); }
        $view = $this->getView('Form', 'html'); $view->form = $rendered; $view->document = $this->app->getDocument(); $view->display();
    }
    public function options(): void
    {
        $this->app->allowCache(false);
        $this->app->setHeader('Cache-Control', 'private, no-store, max-age=0', true);
        $this->app->setHeader('X-Content-Type-Options', 'nosniff', true);
        $status = 200; $result = ['ok' => false]; $runtime = null;
        try {
            if (strtoupper($this->input->getMethod()) !== 'POST') { $this->app->setHeader('Allow', 'POST', true); $status = 405; throw new \InvalidArgumentException('POST required.'); }
            $runtime = $this->app->bootComponent('com_nicode_form_studio')->runtime($this->app);
            $channel = $this->input->post->get('channel', 'component', 'raw');
            if (!is_string($channel) || !in_array($channel, ['component', 'module'], true)) { throw new \InvalidArgumentException('Invalid channel.'); }
            $context = $runtime->get(RequestAdapter::class)->context($channel, true);
            if (!$context->csrfValid) { throw new \Nicode\FormStudio\Submission\SubmissionFailure('session_error'); }
            $ids = RequestAdapter::identities($this->input);
            $spec = $runtime->get(FormRepository::class)->version($ids['form_id'], $ids['version_id']);
            $options = $runtime->get(\Nicode\FormStudio\Application\FormOptions::class);
            $resolved = in_array('repeatable-group', array_column($spec->toArray()['elements'], 'type'), true)
                ? $options->resolveInstances(RequestAdapter::requestInstances($this->input, $spec), $context)
                : $options->resolve(RequestAdapter::request($this->input, $spec), $context);
            $result = ['ok' => true, 'options' => $resolved];
        } catch (\Nicode\FormStudio\Submission\SubmissionFailure $error) {
            $result = ['ok' => false, 'error' => $error->category]; $status = $error->category === 'rate_limited' ? 429 : 403;
            if ($error->retryAfter > 0) { $this->app->setHeader('Retry-After', (string) $error->retryAfter, true); }
        } catch (\OutOfBoundsException) { $result = ['ok' => false, 'error' => 'form_unavailable']; $status = 404; }
        catch (\InvalidArgumentException) { $result = ['ok' => false, 'error' => 'validation_error']; if ($status !== 405) { $status = 422; } }
        catch (\Throwable) {
            $correlation = \Nicode\FormStudio\Domain\Uuid::create(); $status = 503; $result = ['ok' => false, 'error' => 'unexpected_error', 'correlation' => $correlation];
            try { $runtime?->get(\Nicode\FormStudio\Infrastructure\Database\TechnicalLog::class)->record('ERROR', 'datasource.failed', $correlation); } catch (\Throwable) {}
        }
        $this->app->setHeader('Status', (string) $status, true); $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true); $this->app->sendHeaders();
        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); $this->app->close();
    }
    public function submit(): void
    {
        if ($this->input->post->get('row_change', null, 'raw') !== null) { $this->rows(); return; }
        $this->app->allowCache(false);
        $this->app->setHeader('Cache-Control', 'private, no-store, max-age=0', true);
        $this->app->setHeader('X-Content-Type-Options', 'nosniff', true);
        $runtime = null;
        $request = null; $context = null; $declarations = null; $status = 422;
        try {
            if (strtoupper($this->input->getMethod()) !== 'POST') { $this->app->setHeader('Allow', 'POST', true); $status = 405; throw new \InvalidArgumentException('POST required.'); }
            if (RequestAdapter::exceedsPostSize($this->input)) {
                $result = ['accepted' => false, 'category' => 'request_too_large', 'errors' => []]; $status = 413;
            } else {
            $runtime = $this->app->bootComponent('com_nicode_form_studio')->runtime($this->app);
            $channel = $this->input->post->get('channel', 'component', 'raw');
            if (!is_string($channel) || !in_array($channel, ['component', 'module'], true)) { throw new \InvalidArgumentException('Invalid channel.'); }
            $context = $runtime->get(RequestAdapter::class)->context($channel, true);
            if (!$context->csrfValid) { $result = ['accepted' => false, 'category' => 'session_error', 'errors' => []]; $status = 403; }
            else {
                $ids = RequestAdapter::identities($this->input); $forms = $runtime->get(FormRepository::class); $form = $forms->get($ids['form_id']);
                $runtime->get(PublicAccess::class)->assert($form, $context->viewLevels, $context->language, time());
                if ((int) $form['published_version_id'] !== $ids['version_id']) { throw new \OutOfBoundsException('Version unavailable.'); }
                $spec = $forms->version($ids['form_id'], $ids['version_id']);
                $pipeline = $runtime->get(SubmissionPipeline::class);
                if (in_array('repeatable-group', array_column($spec->toArray()['elements'], 'type'), true)) {
                    $envelope = RequestAdapter::requestInstances($this->input, $spec);
                    $request = $envelope->request; $declarations = $envelope->instances->declarations();
                    $result = $pipeline->submitInstances($envelope, $context);
                } else {
                    $request = RequestAdapter::request($this->input, $spec);
                    $result = $pipeline->submit($request, $context);
                }
                $status = $result['accepted'] ? 200 : match ($result['category']) { 'session_error' => 403, 'form_unavailable' => 404, 'rate_limited' => 429, 'persistence_error', 'unexpected_error' => 500, default => 422 };
            }
            }
        } catch (\OutOfBoundsException) { $result = ['accepted' => false, 'category' => 'form_unavailable', 'errors' => []]; $status = 404; }
        catch (\InvalidArgumentException) { $result = ['accepted' => false, 'category' => $status === 405 ? 'method_not_allowed' : 'validation_error', 'errors' => []]; }
        catch (\Throwable) {
            $correlation = \Nicode\FormStudio\Domain\Uuid::create();
            $result = ['accepted' => false, 'category' => 'unexpected_error', 'errors' => [], 'reference' => $correlation, 'correlation' => $correlation]; $status = 500;
            try { $runtime?->get(\Nicode\FormStudio\Infrastructure\Database\TechnicalLog::class)->record('ERROR', 'submission.unexpected', $correlation); } catch (\Throwable) {}
        }
        $result['message'] ??= $this->app->getLanguage()->_('COM_NICODE_FORM_STUDIO_' . strtoupper($result['category']));
        $result['errors'] = RuntimeMessages::errors($result['errors'] ?? [], $this->app->getLanguage(), $result['error_messages'] ?? []);
        unset($result['error_messages']);
        if (($result['retry_after'] ?? 0) > 0) { $this->app->setHeader('Retry-After', (string) $result['retry_after'], true); }
        // PHP can discard the posted format field together with an oversized body.
        $oversizedJson = $result['category'] === 'request_too_large'
            && strtolower(trim((string) $this->input->server->get('HTTP_ACCEPT', '', 'string'))) === 'application/json';
        if ($this->input->getCmd('format', 'html') === 'json' || $oversizedJson) {
            $this->app->setHeader('Status', (string) $status, true);
            $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true); $this->app->sendHeaders();
            echo json_encode($result, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $this->app->close();
            return;
        }
        if ($result['accepted'] && isset($result['redirect'])) { $this->app->redirect($result['redirect'], 303); return; }
        $this->app->setHeader('Status', (string) $status, true);
        $rendered = ['title' => '', 'html' => ''];
        if ($request !== null && $context !== null && ($result['behavior'] ?? 'keep') !== 'hide' && $status < 500) {
            $values = $request->values;
            if (($result['behavior'] ?? '') === 'reset') {
                $preserved = array_flip($result['preserve'] ?? []);
                $values = $declarations === null ? array_intersect_key($values, $preserved)
                    : array_filter($values, static fn (string $address): bool => isset($preserved[\Nicode\FormStudio\Domain\FieldAddress::fromKey($address)->field]), ARRAY_FILTER_USE_KEY);
            }
            try {
                $attempt = $result['next_attempt'] ?? (($result['category'] ?? '') === 'session_error' ? null : $request->attempt);
                $rendered = $runtime->get(FormDisplay::class)->render($request->formId, $context, 'nfs-result-' . bin2hex(random_bytes(6)), Route::_('index.php?option=com_nicode_form_studio&task=form.submit', false), $this->app->getFormToken(), RuntimeMessages::all($this->app->getLanguage()), submitted: $values, errors: $result['errors'] ?? [], attempt: $attempt, declarations: $declarations, reset: ($result['behavior'] ?? '') === 'reset');
                RuntimeAssets::load($this->app);
            } catch (\Throwable) { /* Preserve the confirmed outcome if redisplay becomes unavailable. */ }
        }
        $rendered['result'] = $result;
        $view = $this->getView('Form', 'html'); $view->form = $rendered; $view->document = $this->app->getDocument(); $view->display();
    }
}
