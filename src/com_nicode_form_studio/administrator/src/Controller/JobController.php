<?php
declare(strict_types=1);
namespace Nicode\Component\FormStudio\Administrator\Controller;

defined('_JEXEC') or die;

use Joomla\DI\Container;
use Nicode\FormStudio\Application\{ExportDownloads, JobAdministration};
use Nicode\FormStudio\Infrastructure\Joomla\Authorization;
use Nicode\FormStudio\Jobs\JobWorker;

final class JobController extends AdminJsonController
{
    public function listing(): void
    {
        $this->respond(fn (Container $c, int $actor): array => $c->get(JobAdministration::class)->listing($actor, self::integer($this->input->get('before', PHP_INT_MAX, 'raw'), 1)));
    }
    public function record(): void
    {
        $this->respond(fn (Container $c, int $actor): array => $c->get(JobAdministration::class)->record($actor, $this->queryId()));
    }
    public function enqueue(): void
    {
        $this->respond(function (Container $c, int $actor): array {
            $raw = $this->input->post->get('payload', '', 'raw');
            if (!is_string($raw) || strlen($raw) > 65536) { throw new \InvalidArgumentException('Invalid job payload.'); }
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($data)) { throw new \InvalidArgumentException('Invalid job payload.'); }
            return ['id' => $c->get(JobAdministration::class)->enqueue($actor, self::integer($data['form_id'] ?? null, 0), self::text($data, 'type', 64), $data)];
        }, true);
    }
    public function cancel(): void
    {
        $this->respond(fn (Container $c, int $actor): array => ['cancelled' => $c->get(JobAdministration::class)->cancel($actor, self::integer($this->input->post->get('id', null, 'raw'), 1))], true);
    }
    public function tick(): void
    {
        $this->respond(function (Container $c, int $actor): array {
            $c->get(Authorization::class)->assert($actor, null, 'formstudio.jobs.manage');
            return ['worked' => $c->get(JobWorker::class)->tick(100)];
        }, true);
    }
    public function download(): void
    {
        $this->respond(function (Container $c, int $actor): array {
            $download = $c->get(ExportDownloads::class)->open(self::integer($this->input->post->get('id', null, 'raw'), 1), $actor);
            try {
                foreach ($download->headers as $name => $value) { $this->app->setHeader($name, $value, true); }
                $this->app->sendHeaders(); fpassthru($download->stream);
            } finally { fclose($download->stream); }
            $this->app->close(); return [];
        }, true);
    }
}
