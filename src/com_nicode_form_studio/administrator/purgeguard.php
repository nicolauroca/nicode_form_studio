<?php
declare(strict_types=1);
defined('_JEXEC') or die;

/** Installer-only guard, deliberately independent of the removable library. */
final class NicodeFormStudioPurgeGuard
{
    public static function check(\Joomla\Database\DatabaseInterface $db): bool
    {
        $tables = array_flip($db->getTableList());
        $exists = static fn (string $table): bool => isset($tables[$db->replacePrefix('#__nicode_form_studio_' . $table)]);
        if (!$exists('installation_state')) { return false; }
        $db->setQuery("SELECT state_json FROM #__nicode_form_studio_installation_state WHERE state_key = 'purge'");
        $raw = $db->loadResult(); if ($raw === null) { return false; }
        $state = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (($state['phase'] ?? '') !== 'ready' || ($state['schema'] ?? '') !== '1.0.0') { throw new RuntimeException('Finish FormStudio purge preparation and owned-file cleanup before uninstalling the package.'); }
        foreach (['forms', 'submissions', 'submission_files', 'upload_staging'] as $table) {
            if (!$exists($table)) { continue; }
            $db->setQuery('SELECT id FROM ' . $db->quoteName('#__nicode_form_studio_' . $table), 0, 1);
            if ($db->loadResult() !== null) { throw new RuntimeException('FormStudio purge still contains owned records.'); }
        }
        if ($exists('jobs')) {
            $db->setQuery("SELECT id FROM #__nicode_form_studio_jobs WHERE (job_type IN ('file-cleanup', 'export-cleanup') AND state <> 'completed') OR (job_type IN ('export-csv', 'export-json') AND (result_code IS NULL OR result_code <> 'artifact_expired'))", 0, 1);
            if ($db->loadResult() !== null) { throw new RuntimeException('FormStudio cleanup outbox is not complete.'); }
        }
        return true;
    }
}
