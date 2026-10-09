<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Jobs;

use Nicode\FormStudio\Contract\JobHandlerInterface;
use Nicode\FormStudio\Domain\Diagnostic;
use Nicode\FormStudio\Infrastructure\Database\Connection;
use Nicode\FormStudio\Infrastructure\Database\FormRepository;
use Nicode\FormStudio\Infrastructure\Database\SubmissionRepository;
use Nicode\FormStudio\Infrastructure\Database\JobRepository;

final readonly class ReindexHandler implements JobHandlerInterface
{
    public function __construct(private Connection $db, private FormRepository $forms, private SubmissionRepository $submissions, private JobRepository $jobs, private \Closure $authorize) {}
    public function id(): string { return 'reindex'; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return ['resumable' => true, 'idempotent' => true]; }
    public function validateConfiguration(array $configuration, string $path): array
    {
        if (($configuration['all_forms'] ?? false) === true) { return array_keys($configuration) === ['all_forms'] ? [] : [new Diagnostic('job.scope', $path, 'Full reconstruction cannot include a form or period.')]; }
        if (!is_int($configuration['form_id'] ?? null) || $configuration['form_id'] < 1) { return [new Diagnostic('job.form', $path, 'A form ID is required.')]; }
        if (isset($configuration['submission_id']) && (!is_int($configuration['submission_id']) || $configuration['submission_id'] < 1)) { return [new Diagnostic('job.submission', $path, 'Invalid submission ID.')]; }
        try { new \Nicode\FormStudio\Search\SearchRequest(array_intersect_key($configuration, array_flip(['received_from', 'received_to']))); }
        catch (\InvalidArgumentException) { return [new Diagnostic('job.period', $path, 'Use a valid UTC date interval.')]; }
        if (isset($configuration['received_from'], $configuration['received_to']) && $configuration['received_from'] > $configuration['received_to']) { return [new Diagnostic('job.period', $path, 'The end must not precede the start.')]; }
        return [];
    }
    public function run(JobLease $job, int $limit): JobProgress
    {
        if ($this->validateConfiguration($job->parameters, '/job') !== [] || $limit < 1 || $limit > 500) { throw new \InvalidArgumentException('Invalid reindex chunk.'); }
        if ($job->parameters['all_forms'] ?? false) { return $this->allForms($job, $limit); }
        return $this->db->transaction(function () use ($job, $limit): JobProgress {
            // Same lock order as publication/persistence. No stale chunk can clear a newer pending marker.
            $form = $this->db->row('SELECT published_version_id, state FROM ' . $this->db->table('forms') . ' WHERE id = :form FOR UPDATE', [':form' => $job->parameters['form_id']]);
            if (!$form || $form['state'] === 'deleting' || $form['published_version_id'] === null) { throw new \DomainException('Reindex form unavailable.'); }
            $version = (int) $form['published_version_id'];
            if (isset($job->cursor['index_version']) && $job->cursor['index_version'] !== $version) {
                $job = new JobLease($job->id, $job->uuid, $job->type, $job->creator, $job->parameters, array_intersect_key($job->cursor, ['high_id' => true]), $job->token, $job->revision, $job->processed, $job->failed);
            }
            $progress = $this->form($job, $limit, $this->forms->version($job->parameters['form_id'], $version));
            return new JobProgress($progress->cursor + ['index_version' => $version], $progress->processed, $progress->failed, $progress->complete);
        });
    }

    private function form(JobLease $job, int $limit, \Nicode\FormStudio\Domain\FormSpec $indexPolicy): JobProgress
    {
        $form = $job->parameters['form_id'];
        if (!(($this->authorize)($job->creator, $form, 'formstudio.submissions.reindex'))) { throw new \DomainException('Job permission no longer available.'); }
        $last = (int) ($job->cursor['last_id'] ?? 0);
        $high = isset($job->cursor['high_id']) ? (int) $job->cursor['high_id'] : (int) ($this->db->row('SELECT MAX(id) AS high_id FROM ' . $this->db->table('submissions') . ' WHERE form_id = :form', [':form' => $form])['high_id'] ?? 0);
        if (!($job->cursor['policy_complete'] ?? false)) {
            $policyLast = (int) ($job->cursor['policy_last'] ?? 0);
            $policyHigh = isset($job->cursor['policy_high']) ? (int) $job->cursor['policy_high'] : (int) ($this->db->row('SELECT MAX(id) AS high_id FROM ' . $this->db->table('form_versions') . ' WHERE form_id = :form', [':form' => $form])['high_id'] ?? 0);
            $versions = $this->db->rows('SELECT id FROM ' . $this->db->table('form_versions') . ' WHERE form_id = :form AND id > :last AND id <= :high ORDER BY id LIMIT ' . $limit, [':form' => $form, ':last' => $policyLast, ':high' => $policyHigh]);
            $policies = new \Nicode\FormStudio\Infrastructure\Database\VersionFieldPolicy($this->db);
            foreach ($versions as $version) { $this->jobs->renew($job); $policies->rebuild($form, (int) $version['id'], $indexPolicy); $policyLast = (int) $version['id']; }
            if ($policyLast < $policyHigh && count($versions) === $limit) { return new JobProgress(['policy_last' => $policyLast, 'policy_high' => $policyHigh, 'high_id' => $high], 0); }
        }
        $where = ''; $parameters = [':form' => $form, ':last' => $last, ':high' => $high];
        foreach (['submission_id' => 'id =', 'received_from' => 'received_at >=', 'received_to' => 'received_at <='] as $key => $comparison) {
            if (isset($job->parameters[$key])) { $where .= ' AND ' . $comparison . ' :' . $key; $parameters[':' . $key] = $job->parameters[$key]; }
        }
        $rows = $this->db->rows('SELECT id, form_version_id FROM ' . $this->db->table('submissions') . ' WHERE form_id = :form AND id > :last AND id <= :high' . $where . ' ORDER BY id LIMIT ' . $limit, $parameters);
        $snapshots = [];
        foreach ($rows as $row) {
            $this->jobs->renew($job);
            $version = (int) $row['form_version_id'];
            $snapshots[$version] ??= \Nicode\FormStudio\Search\HistoricalIndexPolicy::apply($this->forms->version($form, $version), $indexPolicy);
            $this->submissions->reindex($form, (int) $row['id'], $snapshots[$version]);
            $last = (int) $row['id'];
        }
        return new JobProgress(['policy_complete' => true, 'last_id' => $last, 'high_id' => $high], count($rows), complete: count($rows) < $limit || $last >= $high);
    }

    private function allForms(JobLease $job, int $limit): JobProgress
    {
        foreach (['core.manage', 'formstudio.submissions.reindex'] as $permission) {
            if (!(($this->authorize)($job->creator, null, $permission))) { throw new \DomainException('Full reindex permission no longer available.'); }
        }
        $high = $job->cursor['high_form'] ?? (int) ($this->db->row('SELECT MAX(id) AS id FROM ' . $this->db->table('forms'))['id'] ?? 0);
        $highSubmission = $job->cursor['high_id'] ?? (int) ($this->db->row('SELECT MAX(id) AS id FROM ' . $this->db->table('submissions'))['id'] ?? 0);
        $last = (int) ($job->cursor['last_form'] ?? 0); $form = (int) ($job->cursor['form_id'] ?? 0);
        if ($form === 0) {
            $row = $this->db->row('SELECT id FROM ' . $this->db->table('forms') . " WHERE id > :last AND id <= :high AND state <> 'deleting' AND published_version_id IS NOT NULL ORDER BY id LIMIT 1", [':last' => $last, ':high' => $high]);
            if ($row === null) { return new JobProgress(['last_form' => $last, 'high_form' => $high, 'high_id' => $highSubmission], 0, complete: true); }
            $form = (int) $row['id'];
        }
        $inner = new JobLease($job->id, $job->uuid, $job->type, $job->creator, ['form_id' => $form], ($job->cursor['form_cursor'] ?? []) + ['high_id' => $highSubmission], $job->token, $job->revision, $job->processed, $job->failed);
        $progress = $this->run($inner, $limit);
        return new JobProgress(['high_form' => $high, 'high_id' => $highSubmission, 'last_form' => $progress->complete ? $form : $last, 'form_id' => $progress->complete ? 0 : $form, 'form_cursor' => $progress->complete ? [] : $progress->cursor], $progress->processed, $progress->failed);
    }
}
