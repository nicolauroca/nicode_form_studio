<?php
declare(strict_types=1);
namespace Nicode\Component\FormStudio\Administrator\Controller;
defined('_JEXEC') or die;
use Joomla\DI\Container;
use Nicode\FormStudio\Application\Templates;

final class TemplateController extends AdminJsonController
{
    public function listing(): void { $this->respond(fn (Container $runtime, int $actor): array => $runtime->get(Templates::class)->listing($actor, $this->input->getString('kind', 'email'), $this->input->getInt('before', PHP_INT_MAX))); }
    public function record(): void { $this->respond(fn (Container $runtime, int $actor): array => $runtime->get(Templates::class)->read($actor, $this->input->getString('kind', 'email'), $this->queryId())); }
    public function create(): void
    {
        $this->respond(function (Container $runtime, int $actor): array { $data = $this->payload(); return ['id' => $runtime->get(Templates::class)->createEmail($actor, self::text($data, 'name', 1020))]; }, true);
    }
    public function save(): void
    {
        $this->respond(function (Container $runtime, int $actor): array {
            $data = $this->payload(); if (!is_array($data['content'] ?? null)) { throw new \InvalidArgumentException('Expected template text.'); }
            return ['revision' => $runtime->get(Templates::class)->saveEmail($actor, self::integer($data['id'] ?? null, 1), self::integer($data['revision'] ?? null, 0), self::text($data, 'name', 1020), self::text($data, 'language', 64), $data['content'])];
        }, true);
    }
    public function bind(): void
    {
        $this->respond(function (Container $runtime, int $actor): array {
            $data = $this->payload(); if (!is_array($data['bindings'] ?? null)) { throw new \InvalidArgumentException('Expected parameter bindings.'); }
            return $runtime->get(Templates::class)->emailConfiguration($actor, self::integer($data['id'] ?? null, 1), self::integer($data['revision'] ?? null, 1), self::integer($data['form_id'] ?? null, 1), $data['bindings']);
        }, true);
    }
    public function capture(): void
    {
        $this->respond(function (Container $runtime, int $actor): array {
            $data = $this->payload();
            return $runtime->get(Templates::class)->captureForm($actor, self::text($data, 'name', 1020), self::integer($data['form_id'] ?? null, 1), self::integer($data['form_revision'] ?? null, 0), self::integer($data['id'] ?? 0, 0), self::integer($data['revision'] ?? 0, 0));
        }, true);
    }
    public function preview(): void { $this->instantiate(false); }
    public function apply(): void { $this->instantiate(true); }
    private function instantiate(bool $commit): void
    {
        $this->respond(function (Container $runtime, int $actor) use ($commit): array {
            $data = $this->payload(); $service = $runtime->get(Templates::class);
            $arguments = [$actor, self::integer($data['id'] ?? null, 1), self::integer($data['revision'] ?? null, 1), self::text($data, 'name', 1020), self::text($data, 'alias', 255)];
            if (!$commit) { return $service->previewForm(...$arguments); }
            if (!is_bool($data['acknowledge_review'] ?? null)) { throw new \InvalidArgumentException('Expected review acknowledgement.'); }
            return $service->createForm(...[...$arguments, self::text($data, 'review_token', 8192), $data['acknowledge_review']]);
        }, true);
    }
    private function payload(): array
    {
        $raw = $this->input->post->get('payload', '', 'raw');
        if (!is_string($raw) || strlen($raw) > 2097152) { throw new \InvalidArgumentException('Invalid template payload size.'); }
        $data = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data)) { throw new \InvalidArgumentException('Expected a template object.'); }
        return $data;
    }
}
