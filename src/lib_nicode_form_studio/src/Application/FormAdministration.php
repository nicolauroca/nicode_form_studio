<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Application;

use Nicode\FormStudio\Contract\CaptchaAdapterInterface;
use Nicode\FormStudio\Domain\CanonicalJson;
use Nicode\FormStudio\Domain\FormSpec;
use Nicode\FormStudio\Domain\Uuid;
use Nicode\FormStudio\Infrastructure\Database\Connection;
use Nicode\FormStudio\Infrastructure\Database\FormRepository;
use Nicode\FormStudio\Security\CaptchaPolicy;

/** Administrative use cases: actor identity must come from Joomla, never request data. */
final readonly class FormAdministration
{
    public function __construct(private FormRepository $forms, private Connection $db, private \Closure $authorize, private \Closure $attachAsset, private CaptchaAdapterInterface $captcha, private ?PublicationReadiness $readiness = null) {}

    public function create(string $name, string $alias, int $actor): int
    {
        $this->assert($actor, null, 'core.create');
        return $this->db->transaction(function () use ($name, $alias, $actor): int {
            $id = $this->forms->create($name, $alias, $actor);
            ($this->attachAsset)($id);
            $this->audit($actor, $id, 'form.create');
            return $id;
        });
    }

    public function edit(int $form, int $actor): array
    {
        $this->assert($actor, $form, 'core.edit');
        $row = $this->forms->get($form);
        // Child tables are removed in separate bounded chunks. Never reconstruct
        // a partially deleted graph just to display deletion status/retry controls.
        $draft = $row['state'] === 'deleting' ? ['schema_version' => '1.0', 'uuid' => $row['uuid'], 'name' => $row['name'], 'elements' => [], 'fields' => [], 'rules' => [], 'actions' => []] : $this->forms->draft($form);
        $publishedNames = [];
        if ($row['state'] !== 'deleting' && $row['published_version_id'] !== null) {
            foreach ($this->forms->version($form, (int) $row['published_version_id'])->toArray()['fields'] as $field) { $publishedNames[$field['uuid']] = $field['name']; }
        }
        return ['form' => $row, 'draft' => $draft, 'published_names' => $publishedNames];
    }

    public function save(int $form, int $revision, array $draft, int $actor): int
    {
        $this->assert($actor, $form, 'core.edit');
        return $this->db->transaction(function () use ($form, $revision, $draft, $actor): int {
            $next = $this->forms->saveDraft($form, $revision, $draft, $actor);
            ($this->attachAsset)($form);
            $this->audit($actor, $form, 'form.save', ['revision' => $next]);
            return $next;
        });
    }

    public function publish(int $form, int $revision, int $actor, string $comment = ''): int
    {
        return $this->publishDetailed($form, $revision, $actor, $comment)['version_id'];
    }

    /** Publication diagnostics come from the same compilation that is activated. */
    public function publishDetailed(int $form, int $revision, int $actor, string $comment = ''): array
    {
        $this->assert($actor, $form, 'formstudio.forms.publish');
        $this->assert($actor, $form, 'core.edit.state');
        return $this->db->transaction(function () use ($form, $revision, $actor, $comment): array {
            $diagnostics = [];
            $version = $this->forms->publish($form, $revision, $actor, $comment, function (FormSpec $spec, array $compiledDiagnostics) use ($actor, $form, &$diagnostics): void {
                $diagnostics = $compiledDiagnostics;
                $retentionAction = $spec->toArray()['privacy']['retention']['action'] ?? 'indefinite';
                if ($retentionAction !== 'indefinite') { $this->assert($actor, $form, 'formstudio.submissions.' . $retentionAction); }
                $policy = $spec->toArray()['security']['captcha'] ?? [];
                $this->captcha->assertAvailable(new CaptchaPolicy($policy['mode'] ?? 'inherit', $policy['provider'] ?? null));
                $this->readiness?->assert($spec);
            });
            $this->audit($actor, $form, 'form.publish', ['version_id' => $version]);
            return ['version_id' => $version, 'diagnostics' => $diagnostics];
        });
    }

    public function deactivate(int $form, int $revision, int $actor, string $state = 'unpublished'): int
    {
        $this->assert($actor, $form, 'formstudio.forms.publish');
        $this->assert($actor, $form, 'core.edit.state');
        if ($state === 'trashed') { $this->assert($actor, $form, 'core.delete'); }
        return $this->db->transaction(function () use ($form, $revision, $actor, $state): int {
            $next = $this->forms->deactivate($form, $revision, $actor, $state);
            $this->audit($actor, $form, 'form.deactivate', ['state' => $state]);
            return $next;
        });
    }

    /** Explicit visible-page selection; all writes commit or all roll back. */
    public function bulk(array $selection, string $operation, int $actor): array
    {
        if (!in_array($operation, ['publish', 'unpublished', 'archived', 'trashed'], true) || !array_is_list($selection) || count($selection) < 1 || count($selection) > 100) { throw new \InvalidArgumentException('Invalid form selection.'); }
        $items = [];
        foreach ($selection as $item) {
            if (!is_array($item) || array_diff(array_keys($item), ['id', 'revision']) !== [] || !is_int($item['id'] ?? null) || $item['id'] < 1 || !is_int($item['revision'] ?? null) || $item['revision'] < 0 || isset($items[$item['id']])) { throw new \InvalidArgumentException('Invalid or duplicate form identity.'); }
            $items[$item['id']] = $item['revision'];
        }
        ksort($items, SORT_NUMERIC);
        // Authorize the complete scope before reporting draft diagnostics.
        foreach ($items as $id => $revision) {
            $this->assert($actor, $id, 'formstudio.forms.publish');
            $this->assert($actor, $id, 'core.edit.state');
            if ($operation === 'trashed') { $this->assert($actor, $id, 'core.delete'); }
        }
        return $this->db->transaction(function () use ($items, $operation, $actor): array {
            foreach ($items as $id => $revision) {
                $row = $this->db->row('SELECT draft_revision, state FROM ' . $this->db->table('forms') . ' WHERE id = :id FOR UPDATE', [':id' => $id]);
                if (!$row || (int) $row['draft_revision'] !== $revision || $row['state'] === 'deleting') { throw new \Nicode\FormStudio\Domain\ConcurrentEdit('Form selection changed.'); }
            }
            $result = [];
            foreach ($items as $id => $revision) {
                if ($operation === 'publish') { $this->publish($id, $revision, $actor); }
                else { $this->deactivate($id, $revision, $actor, $operation); }
                $row = $this->db->row('SELECT f.state, f.draft_revision, f.modified_at, v.revision AS published_revision FROM ' . $this->db->table('forms') . ' f LEFT JOIN ' . $this->db->table('form_versions') . ' v ON v.id = f.published_version_id WHERE f.id = :id', [':id' => $id]);
                $result[] = ['id' => $id, 'revision' => (int) $row['draft_revision'], 'state' => $row['state'], 'published_revision' => $row['published_revision'] === null ? null : (int) $row['published_revision'], 'modified_at' => $row['modified_at']];
            }
            return $result;
        });
    }

    public function restore(int $form, int $version, int $revision, int $actor): int
    {
        $this->assert($actor, $form, 'core.edit');
        return $this->db->transaction(function () use ($form, $version, $revision, $actor): int {
            $next = $this->forms->restore($form, $version, $revision, $actor);
            ($this->attachAsset)($form);
            $this->audit($actor, $form, 'form.restore', ['version_id' => $version, 'revision' => $next]);
            return $next;
        });
    }

    public function history(int $form, int $actor, int $beforeRevision = PHP_INT_MAX): array
    {
        $this->assert($actor, $form, 'core.edit');
        return $this->forms->history($form, $beforeRevision, includeCounts: (bool) ($this->authorize)($actor, $form, 'formstudio.submissions.view'));
    }

    public function snapshot(int $form, int $version, int $actor): array
    {
        $this->assert($actor, $form, 'core.edit');
        if ($version < 0) { throw new \InvalidArgumentException('Invalid version.'); }
        return $version === 0 ? $this->forms->draft($form) : $this->forms->version($form, $version)->toArray();
    }

    public function compare(int $form, int $left, int $right, int $actor): array
    {
        $this->assert($actor, $form, 'core.edit');
        return $this->db->transaction(function () use ($form, $left, $right, $actor): array {
            // Draft writers acquire this same row before replacing child rows.
            if ($this->db->row('SELECT id FROM ' . $this->db->table('forms') . ' WHERE id = :id FOR UPDATE', [':id' => $form]) === null) { throw new \OutOfBoundsException('Form not found.'); }
            $before = $this->snapshot($form, $left, $actor); $after = $this->snapshot($form, $right, $actor);
            if ($left === 0 || $right === 0) { unset($before['provider_dependencies'], $after['provider_dependencies']); }
            return (new \Nicode\FormStudio\Domain\SpecDiff())->compare($before, $after);
        });
    }

    public function settings(int $form, int $revision, array $settings, int $actor): int
    {
        $this->assert($actor, $form, 'core.edit');
        $this->assert($actor, $form, 'core.edit.state');
        return $this->db->transaction(function () use ($form, $revision, $settings, $actor): int {
            $next = $this->forms->settings($form, $revision, $settings, $actor);
            ($this->attachAsset)($form);
            $this->audit($actor, $form, 'form.settings', ['revision' => $next]);
            return $next;
        });
    }

    private function assert(int $actor, ?int $form, string $permission): void
    {
        if ($actor < 1 || !(($this->authorize)($actor, null, 'core.manage')) || !(($this->authorize)($actor, $form, 'formstudio.forms.manage')) || !(($this->authorize)($actor, $form, $permission))) { throw new \DomainException('Form access denied.'); }
    }

    private function audit(int $actor, int $form, string $event, array $metadata = []): void
    {
        $this->db->insert('audit_log', ['correlation_id' => Uuid::create(), 'actor_id' => $actor, 'event_type' => $event, 'form_id' => $form, 'submission_uuid' => null, 'created_at' => gmdate('Y-m-d H:i:s'), 'safe_metadata' => CanonicalJson::encode($metadata)]);
    }
}
