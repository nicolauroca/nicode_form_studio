<?php
declare(strict_types=1);
namespace Nicode\Plugin\Extension\FormStudio\Extension;
defined('_JEXEC') or die;
use Joomla\CMS\Event\Model\AfterSaveEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
final class FormStudio extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array { return ['onExtensionAfterSave' => 'recordConfiguration']; }
    public function recordConfiguration(AfterSaveEvent $event): void
    {
        $subject = $event->getItem();
        if ($event->getContext() !== 'com_config.component' || ($subject->type ?? null) !== 'component' || ($subject->element ?? null) !== 'com_nicode_form_studio') { return; }
        $app = $this->getApplication();
        $runtime = $app->bootComponent('com_nicode_form_studio')->runtime($app);
        $db = $runtime->get(\Nicode\FormStudio\Infrastructure\Database\Connection::class);
        $db->insert('audit_log', ['correlation_id' => \Nicode\FormStudio\Domain\Uuid::create(), 'actor_id' => (int) $app->getIdentity()->id, 'event_type' => 'config.security_saved', 'form_id' => null, 'submission_uuid' => null, 'created_at' => gmdate('Y-m-d H:i:s'), 'safe_metadata' => '{}']);
    }
}
