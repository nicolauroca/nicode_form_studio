<?php
declare(strict_types=1);
namespace Nicode\Component\FormStudio\Administrator\Controller;
defined('_JEXEC') or die;

use Joomla\DI\Container;
use Nicode\FormStudio\Application\FormExchange;

final class DefinitionController extends AdminJsonController
{
    public function export(): void
    {
        $this->respond(fn (Container $runtime, int $actor): array => $runtime->get(FormExchange::class)->export($this->queryId(), self::integer($this->input->get('version', 0, 'raw'), 0), $actor, self::text(['mode' => $this->input->get('mode', 'portable', 'raw')], 'mode', 32)));
    }
    public function preview(): void { $this->write(false); }
    public function import(): void { $this->write(true); }
    private function write(bool $commit): void
    {
        $this->respond(function (Container $runtime, int $actor) use ($commit): array {
            $raw = $this->input->post->get('payload', '', 'raw');
            // JSON wrapping may escape every byte of the bounded inner document.
            if (!is_string($raw) || strlen($raw) > 12590000) { throw new \InvalidArgumentException('Invalid transfer payload.'); }
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data) || array_is_list($data)) { throw new \InvalidArgumentException('Expected transfer object.'); }
            $json = self::text($data, 'document', 2097152); $choices = self::object($data, 'choices');
            $service = $runtime->get(FormExchange::class);
            if (!$commit) { return $service->preview($json, $choices, $actor); }
            if (!is_bool($data['acknowledge_review'] ?? null)) { throw new \InvalidArgumentException('Explicit review acknowledgement required.'); }
            return $service->import($json, $choices, $actor, self::text($data, 'review_token', 100), $data['acknowledge_review']);
        }, true);
    }
}
