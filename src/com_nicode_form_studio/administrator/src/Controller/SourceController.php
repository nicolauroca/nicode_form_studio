<?php
declare(strict_types=1);
namespace Nicode\Component\FormStudio\Administrator\Controller;
defined('_JEXEC') or die;

use Joomla\DI\Container;
use Nicode\FormStudio\Infrastructure\Database\Connection;
use Nicode\FormStudio\Infrastructure\Joomla\Authorization;

final class SourceController extends AdminJsonController
{
    public function categories(): void
    {
        $this->respond(function (Container $runtime, int $actor): array {
            $form = $this->queryId(); $authorization = $runtime->get(Authorization::class);
            $authorization->assert($actor, $form, 'core.edit');
            $authorization->assert($actor, $form, 'formstudio.forms.manage');
            $db = $runtime->get(Connection::class); $q = $db->quote(...);
            $before = self::integer($this->input->get('before', PHP_INT_MAX, 'raw'), 1);
            $search = $this->input->get('search', '', 'raw');
            if (!is_string($search) || mb_strlen($search) > 100) { throw new \InvalidArgumentException('Invalid category search.'); }
            $levels = array_values(array_unique(array_map('intval', $this->app->getIdentity()->getAuthorisedViewLevels())));
            if ($levels === []) { return ['rows' => [], 'next_before' => null]; }
            $parameters = [':before' => $before]; $levelNames = [];
            $ids = $this->input->get('ids', [], 'raw');
            if (!is_array($ids) || !array_is_list($ids) || count($ids) > 500) { throw new \InvalidArgumentException('Invalid selected categories.'); }
            $ids = array_values(array_unique(array_map(static fn ($id): int => self::integer($id, 1), $ids)));
            foreach ($levels as $i => $level) { $name = ':access' . $i; $levelNames[] = $name; $parameters[$name] = $level; }
            $conditions = ['c.' . $q('id') . ' < :before', '(c.' . $q('extension') . " = 'com_content' OR c." . $q('id') . ' = 1)', 'c.' . $q('access') . ' IN (' . implode(',', $levelNames) . ')', 'c.' . $q('published') . ' IN (0, 1)'];
            $ancestorNames = [];
            foreach ($levels as $i => $level) { $name = ':ancestor' . $i; $ancestorNames[] = $name; $parameters[$name] = $level; }
            $conditions[] = 'NOT EXISTS (SELECT 1 FROM ' . $q('#__categories') . ' ancestor WHERE ancestor.' . $q('lft') . ' < c.' . $q('lft') . ' AND ancestor.' . $q('rgt') . ' > c.' . $q('rgt') . ' AND ancestor.' . $q('access') . ' NOT IN (' . implode(',', $ancestorNames) . '))';
            if ($search !== '') { $conditions[] = 'LOWER(c.' . $q('title') . ') LIKE :search'; $parameters[':search'] = '%' . mb_strtolower($search) . '%'; }
            if ($ids !== []) {
                $names = []; foreach ($ids as $i => $id) { $name = ':selected' . $i; $names[] = $name; $parameters[$name] = $id; }
                $conditions[] = 'c.' . $q('id') . ' IN (' . implode(',', $names) . ')';
            }
            $limit = $ids === [] ? 100 : 500;
            $rows = $db->rows('SELECT c.' . $q('id') . ', c.' . $q('title') . ', c.' . $q('language') . ', c.' . $q('published') . ' FROM ' . $q('#__categories') . ' c WHERE ' . implode(' AND ', $conditions) . ' ORDER BY c.' . $q('id') . ' DESC LIMIT ' . ($limit + 1), $parameters);
            $more = count($rows) > $limit; $rows = array_slice($rows, 0, $limit);
            return ['rows' => $rows, 'next_before' => $more ? (int) end($rows)['id'] : null];
        });
    }
}
