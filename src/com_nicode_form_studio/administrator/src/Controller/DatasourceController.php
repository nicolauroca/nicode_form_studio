<?php
declare(strict_types=1);
namespace Nicode\Component\FormStudio\Administrator\Controller;
defined('_JEXEC') or die;
use Joomla\DI\Container;
use Nicode\FormStudio\Application\DataSources;

final class DatasourceController extends AdminJsonController
{
    public function listing(): void { $this->respond(fn (Container $runtime, int $actor): array => $runtime->get(DataSources::class)->listing($actor, self::integer($this->input->get('before', PHP_INT_MAX, 'raw'), 1))); }
    public function record(): void { $this->respond(fn (Container $runtime, int $actor): array => $runtime->get(DataSources::class)->read($actor, $this->queryId())); }
    public function capture(): void
    {
        $this->respond(function (Container $runtime, int $actor): array {
            $data = $this->payload();
            return $runtime->get(DataSources::class)->capture($actor, self::text($data, 'name', 1020), self::integer($data['form_id'] ?? null, 1), self::integer($data['form_revision'] ?? null, 0), self::text($data, 'field_uuid', 36), self::integer($data['id'] ?? 0, 0), self::integer($data['revision'] ?? 0, 0));
        }, true);
    }
    public function configure(): void
    {
        $this->respond(function (Container $runtime, int $actor): array {
            $data = $this->payload(); if (!is_bool($data['enabled'] ?? null)) { throw new \InvalidArgumentException('Invalid resource state.'); }
            return ['revision' => $runtime->get(DataSources::class)->configure($actor, self::integer($data['id'] ?? null, 1), self::integer($data['revision'] ?? null, 1), self::text($data, 'name', 1020), $data['enabled'])];
        }, true);
    }
    public function bind(): void
    {
        $this->respond(function (Container $runtime, int $actor): array {
            $data = $this->payload(); if (!is_array($data['bindings'] ?? null)) { throw new \InvalidArgumentException('Invalid resource bindings.'); }
            return $runtime->get(DataSources::class)->bind($actor, self::integer($data['id'] ?? null, 1), self::integer($data['revision'] ?? null, 1), self::integer($data['form_id'] ?? null, 1), self::text($data, 'field_uuid', 36), $data['bindings']);
        }, true);
    }
    private function payload(): array
    {
        $raw = $this->input->post->get('payload', '', 'raw'); if (!is_string($raw) || strlen($raw) > 2097152) { throw new \InvalidArgumentException('Invalid resource payload.'); }
        $data = json_decode($raw, true, 128, JSON_THROW_ON_ERROR); if (!is_array($data) || array_is_list($data)) { throw new \InvalidArgumentException('Expected resource object.'); } return $data;
    }
}
