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

/** Native administrator endpoints. Identity and permissions never come from JSON. */
final class FormController extends AdminJsonController
{
    public function create(): void { $this->write('create'); }
    public function duplicate(): void { $this->write('duplicate'); }
    public function duplicateElement(): void { $this->write('duplicateElement'); }
    public function save(): void { $this->write('save'); }
    public function publish(): void { $this->write('publish'); }
    public function deactivate(): void { $this->write('deactivate'); }
    public function bulk(): void { $this->write('bulk'); }
    public function deletePermanently(): void { $this->write('delete'); }
    public function deletionReview(): void
    {
        $this->respond(fn (Container $runtime, int $actor): array => $runtime->get(\Nicode\FormStudio\Application\FormDeletion::class)->review($this->queryId(), $actor));
    }
    public function restore(): void { $this->write('restore'); }
    public function settings(): void { $this->write('settings'); }
    public function applyPermissions(): void { $this->write('permissions'); }

    public function permissions(): void
    {
        $this->respond(fn (Container $runtime, int $actor): array => $runtime->get(FormPermissions::class)->read($this->queryId(), $actor, self::integer($this->input->get('group', 1, 'raw'), 1)));
    }

    public function record(): void
    {
        $this->respond(function (Container $runtime, int $actor): array {
            return $runtime->get(FormAdministration::class)->edit($this->queryId(), $actor);
        });
    }

    public function history(): void
    {
        $this->respond(function (Container $runtime, int $actor): array {
            $before = $this->input->get('before', (string) PHP_INT_MAX, 'raw');
            return ['versions' => $runtime->get(FormAdministration::class)->history($this->queryId(), $actor, self::integer($before, 1))];
        });
    }

    public function compare(): void
    {
        $this->respond(fn (Container $runtime, int $actor): array => $runtime->get(FormAdministration::class)->compare($this->queryId(), self::integer($this->input->get('left', null, 'raw'), 0), self::integer($this->input->get('right', null, 'raw'), 0), $actor));
    }

    public function preview(): void
    {
        $this->respond(function (Container $runtime, int $actor): array {
            $locale = $this->input->get('locale', $this->app->getLanguage()->getTag(), 'raw');
            if (!is_string($locale) || preg_match('/^[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8}){0,3}$/D', $locale) !== 1) { throw new \InvalidArgumentException('Invalid preview language.'); }
            $language = new \Joomla\CMS\Language\Language($locale);
            $language->load('com_nicode_form_studio', JPATH_SITE . '/components/com_nicode_form_studio', null, true);
            $language->load('com_nicode_form_studio', JPATH_SITE, null, true, false);
            $identity = $this->app->getIdentity();
            return $runtime->get(FormPreview::class)->render($this->queryId(), $actor, RuntimeMessages::all($language), ['language' => $locale, 'view_levels' => $identity->getAuthorisedViewLevels(), 'user_properties' => ['id' => (int) $identity->id, 'name' => (string) $identity->name, 'username' => (string) $identity->username, 'email' => (string) $identity->email]], self::integer($this->input->get('version', 0, 'raw'), 0));
        });
    }

    public function previewOptions(): void
    {
        $this->respond(function (Container $runtime, int $actor): array {
            $raw = $this->input->post->get('payload', '', 'raw');
            if (!is_string($raw) || strlen($raw) > 2097152) { throw new \InvalidArgumentException('Invalid payload size.'); }
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data) || !is_array($data['values'] ?? null)) { throw new \InvalidArgumentException('Invalid preview values.'); }
            if (isset($data['instances']) && !is_array($data['instances'])) { throw new \InvalidArgumentException('Invalid preview rows.'); }
            $locale = self::text($data, 'locale', 64, 'en-GB');
            if (preg_match('/^[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8}){0,3}$/D', $locale) !== 1) { throw new \InvalidArgumentException('Invalid preview language.'); }
            $identity = $this->app->getIdentity();
            return $runtime->get(FormPreview::class)->options(self::integer($data['id'] ?? null, 1), $actor, self::integer($data['revision'] ?? null, 0), $data['values'], ['language' => $locale, 'view_levels' => $identity->getAuthorisedViewLevels(), 'user_properties' => ['id' => (int) $identity->id, 'name' => (string) $identity->name, 'username' => (string) $identity->username, 'email' => (string) $identity->email]], self::integer($data['version'] ?? 0, 0), $data['instances'] ?? null);
        }, true);
    }

    public function previewRows(): void
    {
        $this->respond(function (Container $runtime, int $actor): array {
            $raw = $this->input->post->get('payload', '', 'raw');
            if (!is_string($raw) || strlen($raw) > 2097152) { throw new \InvalidArgumentException('Invalid payload size.'); }
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data) || !is_array($data['values'] ?? null) || !is_array($data['instances'] ?? null) || (isset($data['row']) && !is_string($data['row']))) { throw new \InvalidArgumentException('Invalid preview rows.'); }
            $locale = self::text($data, 'locale', 64, 'en-GB');
            if (preg_match('/^[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8}){0,3}$/D', $locale) !== 1) { throw new \InvalidArgumentException('Invalid preview language.'); }
            $identity = $this->app->getIdentity(); $language = $this->app->getLanguage();
            $language->load('com_nicode_form_studio', JPATH_SITE, $locale, true);
            return ['form' => $runtime->get(FormPreview::class)->changeRows(self::integer($data['id'] ?? null, 1), $actor, self::integer($data['revision'] ?? null, 0), $data['instances'], $data['values'], self::text($data, 'operation', 16), self::text($data, 'group', 8192), $data['row'] ?? null, self::text($data, 'instance', 128), RuntimeMessages::all($language), ['language'=>$locale,'view_levels'=>$identity->getAuthorisedViewLevels(),'user_properties'=>['id'=>(int)$identity->id,'name'=>(string)$identity->name,'username'=>(string)$identity->username,'email'=>(string)$identity->email]], self::integer($data['version'] ?? 0, 0))];
        }, true);
    }

    private function write(string $operation): void
    {
        $this->respond(function (Container $runtime, int $actor) use ($operation): array {
            $raw = $this->input->post->get('payload', '', 'raw');
            if (!is_string($raw) || strlen($raw) > 2097152) { throw new \InvalidArgumentException('Invalid payload size.'); }
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data) || array_is_list($data)) { throw new \InvalidArgumentException('Expected an object.'); }
            $service = $runtime->get(FormAdministration::class);
            if ($operation === 'bulk') {
                if (!is_array($data['selection'] ?? null)) { throw new \InvalidArgumentException('Expected a form selection.'); }
                return ['rows' => $service->bulk($data['selection'], self::text($data, 'operation', 32), $actor)];
            }
            if ($operation === 'create') {
                return ['id' => $service->create(self::text($data, 'name', 1020), self::text($data, 'alias', 255), $actor), 'revision' => 0];
            }
            $id = self::integer($data['id'] ?? null, 1); $revision = self::integer($data['revision'] ?? null, 0);
            if ($operation === 'duplicateElement') { return $runtime->get(\Nicode\FormStudio\Application\FormDuplicator::class)->duplicateElement($id, $revision, self::text($data, 'element', 36), $actor); }
            if ($operation === 'delete') { return ['job_id' => $runtime->get(\Nicode\FormStudio\Application\FormDeletion::class)->enqueue($id, $revision, $actor, self::text($data, 'confirmation', 36))]; }
            if ($operation === 'duplicate') { return $runtime->get(\Nicode\FormStudio\Application\FormDuplicator::class)->duplicate($id, $revision, $actor, self::text($data, 'name', 1020), self::text($data, 'alias', 255), self::integer($data['version_id'] ?? 0, 0)); }
            if ($operation === 'permissions') {
                return ['id' => $id, 'revision' => $runtime->get(FormPermissions::class)->update($id, $revision, self::text($data, 'rules_hash', 64), $actor, self::integer($data['group'] ?? null, 1), self::object($data, 'rules'))];
            }
            $result = match ($operation) {
                'save' => ['revision' => $service->save($id, $revision, self::object($data, 'draft'), $actor)],
                'publish' => $service->publishDetailed($id, $revision, $actor, self::text($data, 'comment', 4000, '')) + ['revision' => $revision + 1],
                'deactivate' => ['revision' => $service->deactivate($id, $revision, $actor, self::text($data, 'state', 32))],
                'restore' => ['revision' => $service->restore($id, self::integer($data['version_id'] ?? null, 1), $revision, $actor)],
                'settings' => ['revision' => $service->settings($id, $revision, self::object($data, 'settings'), $actor)],
                default => throw new \InvalidArgumentException('Unknown operation.'),
            };
            return ['id' => $id] + $result;
        }, true);
    }

}
