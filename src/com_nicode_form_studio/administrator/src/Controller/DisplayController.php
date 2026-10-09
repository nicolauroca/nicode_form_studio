<?php
declare(strict_types=1);
namespace Nicode\Component\FormStudio\Administrator\Controller;
defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;
use Nicode\FormStudio\Application\{FormAdministration, FormListing};
use Nicode\FormStudio\Infrastructure\Joomla\Authorization;
use Nicode\FormStudio\Registry\{FieldTypeRegistry, ActionRegistry, RuleOperatorRegistry, RuleEffectRegistry, ValidatorRegistry};

final class DisplayController extends BaseController
{
    protected $default_view = 'dashboard';
    public function display($cachable = false, $urlparams = []): static
    {
        try {
        $this->app->allowCache(false);
        $this->app->setHeader('Cache-Control', 'private, no-store, max-age=0', true);
        $runtime = $this->app->bootComponent('com_nicode_form_studio')->runtime($this->app);
        $actor = (int) ($this->app->getIdentity()?->id ?? 0);
        $authorization = $runtime->get(Authorization::class); $authorization->assert($actor, null, 'core.manage');
        $name = $this->input->getCmd('view', 'dashboard');
        if (!in_array($name, ['dashboard', 'forms', 'editor', 'submissions', 'submission', 'jobs', 'health', 'logs', 'audit', 'resources', 'optionset', 'templates', 'emailtemplate', 'datasources', 'datasource', 'purge'], true)) { throw new \RuntimeException('View not found.', 404); }
        $data = ['csrf' => $this->app->getFormToken(), 'canCreate' => $authorization->allows($actor, null, 'core.create') && $authorization->allows($actor, null, 'formstudio.forms.manage')];
        $data['canConfigure'] = $authorization->allows($actor, null, 'core.admin') || $authorization->allows($actor, null, 'core.options');
        $data['canPurge'] = $authorization->allows($actor, null, 'core.admin');
        $data['canDiagnose'] = $authorization->allows($actor, null, 'formstudio.logs.view');
        $data['canResources'] = $authorization->allows($actor, null, 'formstudio.resources.manage') || $authorization->allows($actor, null, 'formstudio.forms.manage');
        $data['canResourceEdit'] = $authorization->allows($actor, null, 'formstudio.resources.manage');
        if ($name === 'dashboard') {
            $data['dashboard'] = $runtime->get(\Nicode\FormStudio\Application\OperationsDashboard::class)->report($actor);
        } elseif ($name === 'purge') {
            $data += $runtime->get(\Nicode\FormStudio\Application\PackagePurge::class)->review($actor);
        } elseif ($name === 'editor') {
            $id = $this->input->getInt('id');
            $data += $runtime->get(FormAdministration::class)->edit($id, $actor);
            $data['providers'] = [];
            foreach (['fields' => FieldTypeRegistry::class, 'actions' => ActionRegistry::class, 'operators' => RuleOperatorRegistry::class, 'effects' => RuleEffectRegistry::class, 'validators' => ValidatorRegistry::class] as $key => $class) { $data['providers'][$key] = $runtime->get($class)->metadata(); }
            $data['canPublish'] = $authorization->allows($actor, $id, 'formstudio.forms.publish') && $authorization->allows($actor, $id, 'core.edit.state');
            $data['canTrash'] = $data['canPublish'] && $authorization->allows($actor, $id, 'core.delete');
            $data['canDelete'] = $authorization->allows($actor, $id, 'core.delete') && $authorization->allows($actor, $id, 'formstudio.submissions.delete');
            $data['canSettings'] = $authorization->allows($actor, $id, 'core.edit.state');
            $data['canPermissions'] = $authorization->allows($actor, null, 'core.admin');
            $db = $runtime->get(\Nicode\FormStudio\Infrastructure\Database\Connection::class);
            $data['accessLevels'] = $db->rows('SELECT id, title FROM ' . $db->quote('#__viewlevels') . ' ORDER BY ordering, id');
            $data['languages'] = $db->rows('SELECT lang_code, title FROM ' . $db->quote('#__languages') . ' WHERE published = 1 ORDER BY ordering, lang_id');
        } elseif ($name === 'health') {
            $data['health'] = $runtime->get(\Nicode\FormStudio\Infrastructure\Joomla\SystemHealth::class)->report($actor);
        } elseif ($name === 'resources') {
            $data += $runtime->get(\Nicode\FormStudio\Application\OptionSets::class)->listing($actor, $this->input->getInt('before', PHP_INT_MAX));
        } elseif ($name === 'datasources') {
            $data += $runtime->get(\Nicode\FormStudio\Application\DataSources::class)->listing($actor, $this->input->getInt('before', PHP_INT_MAX));
        } elseif ($name === 'datasource') {
            $data['record'] = $runtime->get(\Nicode\FormStudio\Application\DataSources::class)->read($actor, $this->input->getInt('id'));
        } elseif ($name === 'templates') {
            $data += $runtime->get(\Nicode\FormStudio\Application\Templates::class)->listing($actor, $this->input->getString('kind', 'email'), $this->input->getInt('before', PHP_INT_MAX));
        } elseif ($name === 'emailtemplate') {
            $data['record'] = $runtime->get(\Nicode\FormStudio\Application\Templates::class)->read($actor, 'email', $this->input->getInt('id'));
        } elseif ($name === 'optionset') {
            $id = $this->input->getInt('id'); $sets = $runtime->get(\Nicode\FormStudio\Application\OptionSets::class);
            $data += $sets->read($actor, $id, $this->input->getInt('revision') ?: null);
            $data['history'] = $sets->history($actor, $id, $this->input->getInt('before_revision', PHP_INT_MAX));
            $data['can_edit'] = $authorization->allows($actor, null, 'formstudio.resources.manage');
        } elseif ($name === 'audit') {
            $filters = [];
            foreach (['correlation_id', 'submission_uuid', 'event_type', 'from', 'to'] as $key) { $value = $this->input->getString($key, ''); if ($value !== '') { $filters[$key] = $value; } }
            foreach (['form_id', 'actor_id'] as $key) {
                $value = $this->input->get($key, '', 'raw');
                if ($value === '') { continue; }
                if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1 || (string) (int) $value !== $value) { throw new \InvalidArgumentException('Invalid audit identity.'); }
                $filters[$key] = (int) $value;
            }
            $data += $runtime->get(\Nicode\FormStudio\Application\AuditLog::class)->page($actor, $filters, $this->input->getInt('before', PHP_INT_MAX));
        } elseif ($name === 'logs') {
            $correlation = $this->input->getString('correlation', '');
            $data += $runtime->get(\Nicode\FormStudio\Infrastructure\Database\TechnicalLog::class)->page($actor, $authorization->allows(...), $this->input->getInt('before', PHP_INT_MAX), $correlation === '' ? null : $correlation);
        } elseif ($name === 'jobs') {
            $data += $runtime->get(\Nicode\FormStudio\Application\JobAdministration::class)->listing($actor, $this->input->getInt('before', PHP_INT_MAX));
        } elseif ($name === 'submission') {
            $data['record'] = $runtime->get(\Nicode\FormStudio\Application\SubmissionExplorer::class)->detail($actor, $this->input->getInt('form_id'), $this->input->getInt('id'), history: ['actions' => $this->input->get('actions_before', PHP_INT_MAX, 'raw'), 'notes' => $this->input->get('notes_before', PHP_INT_MAX, 'raw'), 'audit' => $this->input->get('audit_before', PHP_INT_MAX, 'raw')]);
            $data['retry_actions'] = $runtime->get(\Nicode\FormStudio\Application\ActionRetries::class)->eligible($actor, $this->input->getInt('form_id'), $this->input->getInt('id'));
        } elseif ($name === 'submissions') {
            $filters = [];
            foreach (['uuid', 'state', 'channel', 'action_status', 'received_from', 'received_to'] as $key) { $value = $this->input->getString($key, ''); if ($value !== '') { $filters[$key] = $value; } }
            foreach (['id', 'form_id', 'user_id'] as $key) { $value = $this->input->getInt($key); if ($value > 0) { $filters[$key] = $value; } }
            $cursor = $this->input->getString('cursor', '');
            $rawFields = $this->input->get('field_filters', '[]', 'raw');
            if (!is_string($rawFields) || strlen($rawFields) > 32768) { throw new \InvalidArgumentException('Invalid field filters.'); }
            $fieldFilters = json_decode($rawFields, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($fieldFilters)) { throw new \InvalidArgumentException('Invalid field filters.'); }
            $columns = $this->input->get('columns', [], 'raw');
            if (!is_array($columns)) { throw new \InvalidArgumentException('Invalid columns.'); }
            $preset = $this->input->getInt('preset');
            $sort = $this->input->get('sort', 'received_at_desc', 'raw');
            if (!is_string($sort)) { throw new \InvalidArgumentException('Invalid response order.'); }
            if ($preset > 0) {
                $saved = $runtime->get(\Nicode\FormStudio\Application\SavedSubmissionViews::class)->read($actor, $preset)['query'];
                $filters = $saved['filters']; $fieldFilters = $saved['fields']; $columns = $saved['columns']; $cursor = '';
                $sort = $saved['sort'] ?? 'received_at_desc';
            }
            $data += $runtime->get(\Nicode\FormStudio\Application\SubmissionExplorer::class)->page($actor, new \Nicode\FormStudio\Search\SearchRequest($filters, $fieldFilters, cursor: $cursor === '' ? null : $cursor, sort: $sort), $columns);
            $data['filters'] = $filters;
            $data['field_filters'] = $fieldFilters;
            $data['export_fields'] = []; $data['job_capabilities'] = [];
            $data['canReindexAll'] = $authorization->allows($actor, null, 'formstudio.submissions.reindex');
            $selectedForm = (int) ($filters['form_id'] ?? 0);
            if ($selectedForm > 0 && $data['schema_version'] !== null) {
                foreach ($data['forms'] as $candidate) { if ($candidate['id'] === $selectedForm) { $data['job_capabilities'] = $candidate['capabilities']; break; } }
                $snapshot = $runtime->get(\Nicode\FormStudio\Infrastructure\Database\FormRepository::class)->version($selectedForm, (int) $data['schema_version']);
                foreach ($snapshot->toArray()['fields'] as $field) {
                    $sensitive = (bool) ($field['sensitive'] ?? false);
                    if ($field['type'] !== 'password' && ($field['include_export'] ?? !$sensitive) && (!$sensitive || $authorization->allows($actor, $selectedForm, 'formstudio.submissions.view_sensitive'))) {
                        $data['export_fields'][] = ['uuid' => $field['uuid'], 'label' => $field['config']['label'] ?? $field['name'], 'sensitive' => $sensitive];
                    }
                }
            }
            $exportHandlers = $runtime->get(\Nicode\FormStudio\Registry\JobHandlerRegistry::class);
            $data['export_available'] = $exportHandlers->has('export-csv') && ($exportHandlers->get('export-csv')->metadata()['available'] ?? true);
            $data['saved_views'] = $runtime->get(\Nicode\FormStudio\Application\SavedSubmissionViews::class)->listing($actor, $this->input->getInt('views_before', PHP_INT_MAX));
        } else {
            $filters = [];
            foreach (['search', 'state', 'language'] as $key) { $value = $this->input->getString($key, ''); if ($value !== '') { $filters[$key] = $value; } }
            foreach (['access', 'author'] as $key) { $value = $this->input->getInt($key); if ($value > 0) { $filters[$key] = $value; } }
            $cursor = $this->input->getString('cursor', '');
            $data += $runtime->get(FormListing::class)->page($actor, $filters, $cursor === '' ? null : $cursor);
            $data['filters'] = $filters;
        }
        $assets = $this->app->getDocument()->getWebAssetManager();
        $strings = parse_ini_file(JPATH_ADMINISTRATOR . '/language/en-GB/com_nicode_form_studio.ini', false, INI_SCANNER_RAW);
        foreach (array_keys($strings ?: []) as $key) { \Joomla\CMS\Language\Text::script($key); }
        foreach ($data['providers']['fields'] ?? [] as $metadata) {
            if (is_string($metadata['label_key'] ?? null) && $metadata['label_key'] !== '') { \Joomla\CMS\Language\Text::script($metadata['label_key']); }
        }
        $assets->getRegistry()->addExtensionRegistryFile('com_nicode_form_studio');
        \Nicode\FormStudio\Infrastructure\Joomla\ModuleAssets::register($assets, JPATH_ROOT . '/media/com_nicode_form_studio/js', \Joomla\CMS\Uri\Uri::root() . 'media/com_nicode_form_studio/js');
        $assets->useScript('com_nicode_form_studio.admin')->useStyle('com_nicode_form_studio.admin');
        if ($name === 'submission') { $data['toolbarReturnParams'] = ['form_id' => $this->input->getInt('form_id')]; }
        \Nicode\Component\FormStudio\Administrator\Service\AdminToolbar::build($name, $data, $this->input->getString('kind', 'email'));
        $view = $this->getView(ucfirst($name), 'html'); $view->data = $data; $view->document = $this->app->getDocument(); $view->display();
        return $this;
        } catch (\OutOfBoundsException $error) { throw new \RuntimeException('Requested FormStudio record is unavailable.', 404); }
        catch (\InvalidArgumentException|\JsonException $error) { throw new \RuntimeException('Invalid FormStudio filters or record identifier.', 400); }
        catch (\DomainException $error) { throw new \RuntimeException('FormStudio access denied.', 403); }
    }
}
