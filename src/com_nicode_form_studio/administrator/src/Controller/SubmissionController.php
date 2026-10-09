<?php
declare(strict_types=1);
namespace Nicode\Component\FormStudio\Administrator\Controller;
defined('_JEXEC') or die;

use Joomla\DI\Container;
use Nicode\FormStudio\Application\SubmissionExplorer;
use Nicode\FormStudio\Search\SearchRequest;

final class SubmissionController extends AdminJsonController
{
    public function retry(): void
    {
        $this->respond(function (Container $runtime, int $actor): array {
            $raw = $this->input->post->get('payload', '', 'raw');
            if (!is_string($raw) || strlen($raw) > 32768) { throw new \InvalidArgumentException('Invalid retry payload.'); }
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($data) || !is_string($data['attempts'] ?? null)) { throw new \InvalidArgumentException('Invalid retry attempts.'); }
            $attempts = json_decode($data['attempts'], true, 4, JSON_THROW_ON_ERROR);
            if (!is_array($attempts)) { throw new \InvalidArgumentException('Invalid retry attempts.'); }
            return ['job_id' => $runtime->get(\Nicode\FormStudio\Application\ActionRetries::class)->enqueue($actor, self::integer($data['form_id'] ?? null, 1), self::integer($data['id'] ?? null, 1), $attempts)];
        }, true);
    }
    public function state(): void { $this->mutate('state'); }
    public function note(): void { $this->mutate('note'); }
    public function saveView(): void { $this->mutateView(false); }
    public function removeView(): void { $this->mutateView(true); }
    public function savedView(): void
    {
        $this->respond(fn (Container $runtime, int $actor): array => $runtime->get(\Nicode\FormStudio\Application\SavedSubmissionViews::class)->read($actor, $this->queryId()));
    }
    private function mutateView(bool $remove): void
    {
        $this->respond(function (Container $runtime, int $actor) use ($remove): array {
            $raw = $this->input->post->get('payload', '', 'raw');
            if (!is_string($raw) || strlen($raw) > 100000) { throw new \InvalidArgumentException('Invalid payload.'); }
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($data)) { throw new \InvalidArgumentException('Invalid payload.'); }
            $service = $runtime->get(\Nicode\FormStudio\Application\SavedSubmissionViews::class);
            if ($remove) { $service->remove($actor, self::integer($data['id'] ?? null, 1)); return []; }
            return ['id' => $service->create($actor, self::text($data, 'name', 1020), self::object($data, 'query'))];
        }, true);
    }
    private function mutate(string $operation): void
    {
        $this->respond(function (Container $runtime, int $actor) use ($operation): array {
            $raw = $this->input->post->get('payload', '', 'raw');
            if (!is_string($raw) || strlen($raw) > 100000) { throw new \InvalidArgumentException('Invalid payload.'); }
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($data)) { throw new \InvalidArgumentException('Invalid payload.'); }
            $service = $runtime->get(\Nicode\FormStudio\Application\SubmissionAdministration::class);
            $form = self::integer($data['form_id'] ?? null, 1); $id = self::integer($data['id'] ?? null, 1);
            if ($operation === 'note') { return ['note_id' => $service->addNote($actor, $form, $id, self::text($data, 'body', 16000))]; }
            $service->changeState($actor, $form, $id, self::text($data, 'expected', 32), self::text($data, 'state', 32));
            return ['state' => $data['state']];
        }, true);
    }
    public function search(): void
    {
        $this->respond(function (Container $runtime, int $actor): array {
            $raw = $this->input->get('query', '{}', 'raw');
            if (!is_string($raw) || strlen($raw) > 32768) { throw new \InvalidArgumentException('Invalid query.'); }
            $query = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($query) || !is_array($query['filters'] ?? []) || !is_array($query['fields'] ?? [])) { throw new \InvalidArgumentException('Invalid query.'); }
            $cursor = $query['cursor'] ?? null;
            if ($cursor !== null && (!is_string($cursor) || strlen($cursor) > 8192)) { throw new \InvalidArgumentException('Invalid cursor.'); }
            if (!is_array($query['columns'] ?? [])) { throw new \InvalidArgumentException('Invalid columns.'); }
            if (!is_string($query['sort'] ?? 'received_at_desc')) { throw new \InvalidArgumentException('Invalid order.'); }
            return $runtime->get(SubmissionExplorer::class)->page($actor, new SearchRequest($query['filters'] ?? [], $query['fields'] ?? [], self::integer($query['limit'] ?? 50, 1), $cursor, sort: $query['sort'] ?? 'received_at_desc'), $query['columns'] ?? []);
        });
    }

    public function record(): void
    {
        $this->respond(fn (Container $runtime, int $actor): array => $runtime->get(SubmissionExplorer::class)->detail($actor, self::integer($this->input->get('form_id', null, 'raw'), 1), $this->queryId(), history: ['actions' => $this->input->get('actions_before', PHP_INT_MAX, 'raw'), 'notes' => $this->input->get('notes_before', PHP_INT_MAX, 'raw'), 'audit' => $this->input->get('audit_before', PHP_INT_MAX, 'raw')]));
    }

    public function download(): void
    {
        $this->respond(function (Container $runtime, int $actor): array {
            $uuid = $this->input->post->get('file', '', 'raw');
            if (!is_string($uuid)) { throw new \InvalidArgumentException('Invalid file.'); }
            $download = $runtime->get(\Nicode\FormStudio\Application\FileDownloads::class)->open(self::integer($this->input->post->get('form_id', null, 'raw'), 1), $uuid, $actor);
            try {
                foreach ($download->headers as $name => $value) { $this->app->setHeader($name, $value, true); }
                $this->app->sendHeaders(); fpassthru($download->stream);
            } finally { fclose($download->stream); }
            $this->app->close();
            return [];
        }, true);
    }

    /** Revealing sensitive values is an explicit CSRF-protected, audited action. */
    public function reveal(): void
    {
        $this->respond(function (Container $runtime, int $actor): array {
            $raw = $this->input->post->get('payload', '', 'raw');
            if (!is_string($raw) || strlen($raw) > 1024) { throw new \InvalidArgumentException('Invalid payload.'); }
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($data)) { throw new \InvalidArgumentException('Invalid payload.'); }
            return $runtime->get(SubmissionExplorer::class)->detail($actor, self::integer($data['form_id'] ?? null, 1), self::integer($data['id'] ?? null, 1), true);
        }, true);
    }
}
