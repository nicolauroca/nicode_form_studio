<?php
declare(strict_types=1);
namespace Nicode\Component\FormStudio\Administrator\Controller;
defined('_JEXEC') or die;
use Joomla\DI\Container;
use Nicode\FormStudio\Application\OptionSets;

final class OptionsetController extends AdminJsonController
{
    public function create(): void
    {
        $this->respond(function (Container $runtime, int $actor): array { $data = $this->payload(); return ['id' => $runtime->get(OptionSets::class)->create($actor, self::text($data, 'name', 1020))]; }, true);
    }
    public function save(): void
    {
        $this->respond(function (Container $runtime, int $actor): array {
            $data = $this->payload();
            if (!is_array($data['options'] ?? null)) { throw new \InvalidArgumentException('Expected option rows.'); }
            return ['revision' => $runtime->get(OptionSets::class)->save($actor, self::integer($data['id'] ?? null, 1), self::integer($data['revision'] ?? null, 0), self::text($data, 'name', 1020), $data['options'])];
        }, true);
    }
    public function record(): void
    {
        $this->respond(function (Container $runtime, int $actor): array {
            $revision = $this->input->get('revision', null, 'raw');
            return $runtime->get(OptionSets::class)->read($actor, $this->queryId(), $revision === null ? null : self::integer($revision, 1));
        });
    }
    public function listing(): void
    {
        $this->respond(fn (Container $runtime, int $actor): array => $runtime->get(OptionSets::class)->listing($actor, $this->input->getInt('before', PHP_INT_MAX)));
    }
    public function source(): void
    {
        $this->respond(function (Container $runtime, int $actor): array {
            $data = $this->payload();
            if (!is_array($data['bindings'] ?? [])) { throw new \InvalidArgumentException('Invalid dependency bindings.'); }
            return $runtime->get(OptionSets::class)->source($actor, self::integer($data['id'] ?? null, 1), self::integer($data['revision'] ?? null, 1), $data['bindings'] ?? []);
        }, true);
    }
    private function payload(): array
    {
        $raw = $this->input->post->get('payload', '', 'raw');
        if (!is_string($raw) || strlen($raw) > 2097152) { throw new \InvalidArgumentException('Invalid resource payload size.'); }
        $data = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data)) { throw new \InvalidArgumentException('Expected a resource object.'); }
        return $data;
    }
}
