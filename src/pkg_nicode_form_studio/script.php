<?php
declare(strict_types=1);
defined('_JEXEC') or die;

use Joomla\CMS\Installer\{InstallerAdapter, InstallerScriptInterface};
use Joomla\Database\{DatabaseAwareInterface, DatabaseAwareTrait};

/** Check prerequisites before the package changes any child extension. */
return new class implements InstallerScriptInterface, DatabaseAwareInterface {
    use DatabaseAwareTrait;
    public function install(InstallerAdapter $adapter): bool { return true; }
    public function update(InstallerAdapter $adapter): bool { return true; }
    public function uninstall(InstallerAdapter $adapter): bool { return true; }
    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if (!in_array($type, ['install', 'update', 'discover_install'], true)) { return true; }
        $app = \Joomla\CMS\Factory::getApplication();
        $version = (string) $adapter->getManifest()->version;
        if (!$app->isClient('administrator')) { echo "Nicode Form Studio " . $version . " installed. Documentation: https://github.com/nicolauroca/nicode_form_studio\n"; return true; }
        $app->getLanguage()->load('com_nicode_form_studio', JPATH_ADMINISTRATOR, null, true);
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $t = static fn (string $key): string => $e(\Joomla\CMS\Language\Text::_('COM_NICODE_FORM_STUDIO_INSTALL_' . $key));
        $url = static fn (string $view): string => $e(\Joomla\CMS\Router\Route::_('index.php?option=com_nicode_form_studio&view=' . $view, false));
        $repo = 'https://github.com/nicolauroca/nicode_form_studio';
        echo '<section class="card my-4" aria-labelledby="nfs-install-title" data-nfs-install-summary><div class="card-header"><h2 id="nfs-install-title" class="mb-0">Nicode Form Studio <span class="badge bg-success">' . $e($version) . '</span></h2></div><div class="card-body">';
        echo '<p class="lead">' . $t($type === 'update' ? 'UPDATED' : 'READY') . '</p><p>' . $t('INTRO') . '</p>';
        echo '<div class="d-flex flex-wrap gap-2 mb-4"><a class="btn btn-primary" href="' . $url('dashboard') . '"><span class="icon-home" aria-hidden="true"></span> ' . $t('OPEN') . '</a><a class="btn btn-outline-primary" href="' . $url('health') . '">' . $t('HEALTH') . '</a></div>';
        echo '<div class="row g-4"><div class="col-lg-6"><h3>' . $t('NEXT') . '</h3><ol>';
        foreach (['STEP_CONFIG', 'STEP_FORM', 'STEP_PUBLISH', 'STEP_JOBS'] as $step) { echo '<li class="mb-2">' . $t($step) . '</li>'; }
        echo '</ol><p>' . $t('DATA') . '</p></div><div class="col-lg-6"><h3>' . $t('CONTENTS') . '</h3><ul>';
        foreach (['COMPONENT', 'LIBRARY', 'MODULE', 'TASK', 'GUARD'] as $item) { echo '<li>' . $t($item) . '</li>'; }
        echo '</ul><h3>' . $t('DEVELOPERS') . '</h3><p>' . $t('ENVIRONMENT') . '</p><ul>';
        foreach (['ADMIN_GUIDE' => '/blob/main/docs/ADMIN_USER_GUIDE.md', 'DEV_GUIDE' => '/blob/main/docs/DEVELOPER_GUIDE.md', 'CHANGELOG' => '/blob/main/CHANGELOG.md', 'REPOSITORY' => '', 'ISSUES' => '/issues'] as $label => $path) {
            echo '<li><a href="' . $repo . $path . '" target="_blank" rel="noopener noreferrer">' . $t($label) . '</a></li>';
        }
        echo '</ul></div></div><hr><p class="mb-0 text-muted">Nicode · GPL-2.0-or-later · Joomla 6+ · PHP 8.3+</p></div></section>';
        return true;
    }
    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'uninstall') {
            $guard = JPATH_ADMINISTRATOR . '/components/com_nicode_form_studio/purgeguard.php';
            if (is_file($guard)) { require_once $guard; NicodeFormStudioPurgeGuard::check($this->getDatabase()); }
            return true;
        }
        // A renamed extension is a new Joomla identity, not an in-place upgrade.
        // Refuse before installing children when legacy code or retained data exists.
        $legacyTables = $this->getDatabase()->getTableList();
        $legacyPrefix = $this->getDatabase()->replacePrefix('#__nicode_easyforms_');
        if (is_dir(JPATH_ADMINISTRATOR . '/components/com_nicode_easy_forms')
            || is_dir(JPATH_LIBRARIES . '/nicode_easy_forms')
            || array_filter($legacyTables, static fn (string $table): bool => str_starts_with($table, $legacyPrefix))) {
            throw new RuntimeException('Nicode Form Studio cannot upgrade an existing Nicode EasyForms installation automatically. Use a separate clean Joomla site; preserve the original site and data until a migration is validated.');
        }
        $guard = JPATH_ADMINISTRATOR . '/components/com_nicode_form_studio/purgeguard.php';
        if (is_file($guard)) {
            require_once $guard;
            if (NicodeFormStudioPurgeGuard::check($this->getDatabase())) { throw new RuntimeException('Complete the prepared FormStudio uninstall before installing another package version.'); }
        }
        if (version_compare(PHP_VERSION, '8.3.0', '<') || version_compare(JVERSION, '6.0.0', '<')) { throw new RuntimeException('Nicode Form Studio requires PHP 8.3 and Joomla 6 or newer.'); }
        foreach (['mbstring', 'intl', 'bcmath', 'fileinfo', 'curl', 'zip'] as $extension) {
            if (!extension_loaded($extension)) { throw new RuntimeException('Nicode Form Studio requires PHP extension: ' . $extension); }
        }
        $db = $this->getDatabase(); $type = $db->getServerType(); $version = $db->getVersion();
        $maria = stripos($version, 'mariadb') !== false;
        if (!in_array($type, ['mysql', 'postgresql'], true) || version_compare($version, $type === 'postgresql' ? '14.0' : ($maria ? '10.6.0' : '8.0.13'), '<')) { throw new RuntimeException('Nicode Form Studio requires MySQL 8.0.13, MariaDB 10.6 or PostgreSQL 14 or newer.'); }
        return true;
    }
};
