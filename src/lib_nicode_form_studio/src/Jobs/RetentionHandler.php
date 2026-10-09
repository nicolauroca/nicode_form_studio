<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Jobs;

use Nicode\FormStudio\Contract\TransactionalJobHandlerInterface;
use Nicode\FormStudio\Domain\Diagnostic;
use Nicode\FormStudio\Infrastructure\Database\Connection;
use Nicode\FormStudio\Infrastructure\Database\JobRepository;
use Nicode\FormStudio\Infrastructure\Database\SubmissionMaintenance;

final readonly class RetentionHandler implements TransactionalJobHandlerInterface
{
    public function __construct(private Connection $db, private JobRepository $jobs, private SubmissionMaintenance $maintenance, private \Closure $authorize) {}
    public function id(): string { return 'retention'; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return ['resumable' => true, 'idempotent' => true]; }
    public function validateConfiguration(array $configuration, string $path): array
    {
        if (isset($configuration['version_id']) && (!is_int($configuration['version_id']) || $configuration['version_id'] < 1)) { return [new Diagnostic('job.retention_version', $path, 'Invalid historical retention version.')]; }
        return is_int($configuration['form_id'] ?? null) && $configuration['form_id'] > 0 && in_array($configuration['operation'] ?? '', ['delete', 'anonymize'], true) ? [] : [new Diagnostic('job.retention', $path, 'A form and supported retention operation are required.')];
    }
    public function run(JobLease $job, int $limit): JobProgress
    {
        if ($this->validateConfiguration($job->parameters, '/job') !== [] || $limit < 1 || $limit > 500) { throw new \InvalidArgumentException('Invalid retention chunk.'); }
        $form = $job->parameters['form_id']; $operation = $job->parameters['operation'];
        if (!(($this->authorize)($job->creator, $form, 'formstudio.submissions.' . $operation))) { throw new \DomainException('Retention permission unavailable.'); }
        $cutoff = $job->cursor['cutoff'] ?? gmdate('Y-m-d H:i:s'); $last = (int) ($job->cursor['last_id'] ?? 0);
        $parameters = [':form' => $form, ':cutoff' => $cutoff, ':last' => $last];
        if (isset($job->parameters['version_id'])) { $parameters[':version'] = $job->parameters['version_id']; }
        $rows = $this->db->rows('SELECT id FROM ' . $this->db->table('submissions') . ' WHERE form_id = :form AND expires_at <= :cutoff AND id > :last' . (isset($parameters[':version']) ? ' AND form_version_id = :version' : '') . ' ORDER BY id LIMIT ' . $limit, $parameters);
        foreach ($rows as $row) { $this->jobs->renew($job); $this->maintenance->apply($form, (int) $row['id'], $job->creator, $operation); $last = (int) $row['id']; }
        return new JobProgress(['last_id' => $last, 'cutoff' => $cutoff], count($rows), complete: count($rows) < $limit);
    }
}
