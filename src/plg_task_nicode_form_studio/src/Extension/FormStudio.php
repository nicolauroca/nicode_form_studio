<?php
declare(strict_types=1);
namespace Nicode\Plugin\Task\FormStudio\Extension;
defined('_JEXEC') or die;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Event\SubscriberInterface;
final class FormStudio extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;
    protected $autoloadLanguage = true;
    protected const TASKS_MAP = ['nicode.formstudio.jobs' => ['langConstPrefix' => 'PLG_TASK_NICODE_FORM_STUDIO', 'form' => 'worker', 'method' => 'runJobs']];
    public static function getSubscribedEvents(): array
    {
        return ['onTaskOptionsList' => 'advertiseRoutines', 'onExecuteTask' => 'standardRoutineHandler', 'onContentPrepareForm' => 'enhanceTaskItemForm'];
    }
    protected function runJobs(ExecuteTaskEvent $event): int
    {
        $params = $event->getArgument('params');
        $batches = filter_var($params->batches ?? 5, FILTER_VALIDATE_INT);
        $size = filter_var($params->chunk_size ?? 100, FILTER_VALIDATE_INT);
        if ($batches === false || $batches < 1 || $batches > 50 || $size === false || $size < 1 || $size > 500) { return Status::KNOCKOUT; }
        try {
            $app = $this->getApplication();
            $runtime = $app->bootComponent('com_nicode_form_studio')->runtime($app);
            $runtime->get(\Nicode\FormStudio\Infrastructure\Joomla\JobMaintenance::class)->queueRetention();
            $runtime->get(\Nicode\FormStudio\Infrastructure\Joomla\JobMaintenance::class)->queueUploadCleanup();
            $runtime->get(\Nicode\FormStudio\Infrastructure\Joomla\JobMaintenance::class)->queueRateLimitCleanup();
            $runtime->get(\Nicode\FormStudio\Infrastructure\Joomla\JobMaintenance::class)->queueAttemptCleanup();
            $retention = (int) \Joomla\CMS\Component\ComponentHelper::getParams('com_nicode_form_studio')->get('technical_log_days', 30);
            $runtime->get(\Nicode\FormStudio\Infrastructure\Joomla\JobMaintenance::class)->queueTechnicalLogCleanup($retention);
            $historyParams = \Joomla\CMS\Component\ComponentHelper::getParams('com_nicode_form_studio');
            foreach (['audit' => 'audit_log_days', 'action' => 'action_history_days'] as $kind => $setting) {
                $runtime->get(\Nicode\FormStudio\Infrastructure\Joomla\JobMaintenance::class)->queueHistoryCleanup($kind, (int) $historyParams->get($setting, 0));
            }
            if ($runtime->get(\Nicode\FormStudio\Registry\JobHandlerRegistry::class)->has('export-cleanup')) { $runtime->get(\Nicode\FormStudio\Infrastructure\Joomla\JobMaintenance::class)->queueExportCleanup(); }
            $worker = $runtime->get(\Nicode\FormStudio\Jobs\JobWorker::class);
            $deadline = microtime(true) + 20;
            for ($i = 0; $i < $batches && microtime(true) < $deadline; $i++) { if (!$worker->tick($size)) { break; } }
            return Status::OK;
        } catch (\Nicode\FormStudio\Jobs\LeaseLost) { return Status::OK; }
        catch (\Throwable) { $this->logTask('formstudio_worker_failed', 'error'); return Status::KNOCKOUT; }
    }
}
