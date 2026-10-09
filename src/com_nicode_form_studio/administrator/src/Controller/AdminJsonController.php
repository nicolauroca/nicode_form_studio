<?php
declare(strict_types=1);
namespace Nicode\Component\FormStudio\Administrator\Controller;
defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\DI\Container;
use Nicode\FormStudio\Application\{FormAdministration, FormPreview};
use Nicode\FormStudio\Compiler\CompilationException;
use Nicode\FormStudio\Domain\ConcurrentEdit;
use Nicode\FormStudio\Infrastructure\Joomla\{Authorization, FormPermissions, RequestAdapter, RuntimeMessages};

/** Shared native identity, method, CSRF and safe error boundary. */
abstract class AdminJsonController extends BaseController
{
    protected function respond(\Closure $action, bool $write = false): void
    {
        $status = 200; $result = [];
        $this->app->allowCache(false);
        $this->app->setHeader('Cache-Control', 'private, no-store, max-age=0', true);
        $this->app->setHeader('X-Content-Type-Options', 'nosniff', true);
        try {
            $method = strtoupper($this->input->getMethod());
            if ($method !== ($write ? 'POST' : 'GET')) {
                $status = 405; $this->app->setHeader('Allow', $write ? 'POST' : 'GET', true);
                $result = ['ok' => false, 'error' => 'method_not_allowed'];
            } else {
                $runtime = $this->app->bootComponent('com_nicode_form_studio')->runtime($this->app);
                $actor = (int) ($this->app->getIdentity()?->id ?? 0);
                $runtime->get(Authorization::class)->assert($actor, null, 'core.manage');
                if ($write && !in_array(strtolower($this->input->getCmd('task')), ['purge.prepare', 'job.tick', 'form.deletepermanently'], true)) { $runtime->get(\Nicode\FormStudio\Infrastructure\Database\PurgeState::class)->assertWritable(); }
                if ($write && !$runtime->get(RequestAdapter::class)->context('component', true)->csrfValid) {
                    $status = 403; $result = ['ok' => false, 'error' => 'session_error'];
                } else { $result = ['ok' => true, 'data' => $action($runtime, $actor)]; }
            }
        } catch (ConcurrentEdit) { $status = 409; $result = ['ok' => false, 'error' => 'concurrent_edit']; }
        catch (CompilationException $error) { $status = 422; $result = ['ok' => false, 'error' => 'compilation_failed', 'diagnostics' => $error->diagnostics, 'correlation' => $this->logFailure($runtime ?? null, 'compiler.failed')]; }
        catch (\OutOfBoundsException) { $status = 404; $result = ['ok' => false, 'error' => 'not_found']; }
        catch (\InvalidArgumentException|\JsonException) { $status = 422; $result = ['ok' => false, 'error' => 'invalid_request']; }
        catch (\DomainException) { $status = 403; $result = ['ok' => false, 'error' => 'access_denied']; }
        catch (\Throwable) { $status = 500; $result = ['ok' => false, 'error' => 'unexpected_error', 'correlation' => $this->logFailure($runtime ?? null, 'admin.unexpected')]; }
        $this->app->setHeader('Status', (string) $status, true);
        $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $this->app->sendHeaders();
        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $this->app->close();
    }

    protected function queryId(): int { return self::integer($this->input->get('id', null, 'raw'), 1); }
    private function logFailure(?Container $runtime, string $event): string
    {
        $correlation = \Nicode\FormStudio\Domain\Uuid::create();
        try { $runtime?->get(\Nicode\FormStudio\Infrastructure\Database\TechnicalLog::class)->record('ERROR', $event, $correlation); }
        catch (\Throwable) { /* Preserve the safe administrative error. */ }
        return $correlation;
    }
    protected static function integer(mixed $value, int $minimum): int
    {
        if ((!is_int($value) && !is_string($value)) || preg_match('/^(0|[1-9][0-9]*)$/D', (string) $value) !== 1 || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $minimum) { throw new \InvalidArgumentException('Invalid integer.'); }
        return (int) $value;
    }
    protected static function text(array $data, string $key, int $bytes, ?string $default = null): string
    {
        $value = $data[$key] ?? $default;
        if (!is_string($value) || strlen($value) > $bytes || !mb_check_encoding($value, 'UTF-8')) { throw new \InvalidArgumentException('Invalid text.'); }
        return $value;
    }
    protected static function object(array $data, string $key): array
    {
        if (!is_array($data[$key] ?? null) || array_is_list($data[$key])) { throw new \InvalidArgumentException('Invalid object.'); }
        return $data[$key];
    }
}
