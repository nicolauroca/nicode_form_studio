<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Jobs;
use Nicode\FormStudio\Contract\TransactionalJobHandlerInterface;
use Nicode\FormStudio\Domain\CanonicalJson;
use Nicode\FormStudio\Infrastructure\Database\{Connection, FormRepository, JobRepository};

/** Walk the expiry index; historical versions, not current drafts, own retention. */
final readonly class RetentionDispatchHandler implements TransactionalJobHandlerInterface
{
    public function __construct(private Connection $db, private FormRepository $forms, private JobRepository $jobs) {}
    public function id(): string { return 'retention-dispatch'; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return ['resumable' => true, 'internal_only' => true]; }
    public function validateConfiguration(array $configuration, string $path): array { return []; }
    public function run(JobLease $job, int $limit): JobProgress
    {
        if ($limit < 1 || $limit > 500) { throw new \InvalidArgumentException('Invalid retention scan limit.'); }
        $cutoff = $job->cursor['cutoff'] ?? gmdate('Y-m-d H:i:s');
        $lastDate = $job->cursor['expires_at'] ?? '1000-01-01 00:00:00'; $lastId = (int) ($job->cursor['id'] ?? 0);
        $rows = $this->db->rows('SELECT id, form_id, form_version_id, expires_at FROM ' . $this->db->table('submissions') . ' WHERE expires_at <= :cutoff AND (expires_at > :last_date OR (expires_at = :equal_date AND id > :last_id)) ORDER BY expires_at, id LIMIT ' . $limit, [':cutoff' => $cutoff, ':last_date' => $lastDate, ':equal_date' => $lastDate, ':last_id' => $lastId]);
        $seen = [];
        foreach ($rows as $row) {
            $lastDate = $row['expires_at']; $lastId = (int) $row['id'];
            $version = (int) $row['form_version_id']; $form = (int) $row['form_id'];
            if (isset($seen[$version])) { continue; } $seen[$version] = true;
            $owner = $this->db->row('SELECT state FROM ' . $this->db->table('forms') . ' WHERE id = :id FOR UPDATE', [':id' => $form]);
            if (!$owner || $owner['state'] === 'deleting') { continue; }
            $this->jobs->renew($job);
            $spec = $this->forms->version($form, $version);
            $operation = $spec->toArray()['privacy']['retention']['action'] ?? 'indefinite';
            if (!in_array($operation, ['delete', 'anonymize'], true)) { continue; }
            $parameters = ['form_id' => $form, 'version_id' => $version, 'operation' => $operation];
            $last = $this->db->row('SELECT state, created_at FROM ' . $this->db->table('jobs') . " WHERE form_id = :form AND job_type = 'retention' AND parameters = :parameters ORDER BY id DESC LIMIT 1", [':form' => $form, ':parameters' => CanonicalJson::encode($parameters)]);
            if ($last !== null && (in_array($last['state'], ['pending', 'running', 'retryable'], true) || strtotime($last['created_at'] . ' UTC') > time() - 3600)) { continue; }
            $author = $this->db->row('SELECT published_by FROM ' . $this->db->table('form_versions') . ' WHERE id = :version AND form_id = :form', [':version' => $version, ':form' => $form]);
            $this->jobs->enqueue('retention', $parameters, (int) $author['published_by']);
        }
        return new JobProgress(['cutoff' => $cutoff, 'expires_at' => $lastDate, 'id' => $lastId], count($rows), complete: count($rows) < $limit);
    }
}
