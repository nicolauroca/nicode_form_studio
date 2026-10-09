<?php
declare(strict_types=1);
namespace Nicode\Component\FormStudio\Administrator\Controller;
defined('_JEXEC') or die;
use Joomla\DI\Container;
use Nicode\FormStudio\Application\PackagePurge;

final class PurgeController extends AdminJsonController
{
    public function review(): void { $this->respond(static fn (Container $runtime, int $actor): array => $runtime->get(PackagePurge::class)->review($actor)); }
    public function prepare(): void
    {
        $this->respond(function (Container $runtime, int $actor): array {
            $confirmation = $this->input->post->get('confirmation', '', 'raw'); $phrase = $this->input->post->get('phrase', '', 'raw');
            if (!is_string($confirmation) || strlen($confirmation) !== 64 || !is_string($phrase) || strlen($phrase) > 100) { throw new \InvalidArgumentException('Invalid purge confirmation.'); }
            return ['job_id' => $runtime->get(PackagePurge::class)->prepare($actor, $confirmation, $phrase)];
        }, true);
    }
}
