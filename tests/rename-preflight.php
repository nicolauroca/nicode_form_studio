<?php
declare(strict_types=1);

namespace Joomla\CMS\Installer {
    interface InstallerScriptInterface {}
    class InstallerAdapter {}
}
namespace Joomla\Database {
    interface DatabaseAwareInterface {}
    trait DatabaseAwareTrait {
        public object $database;
        public function getDatabase(): object { return $this->database; }
    }
}
namespace {
    define('_JEXEC', 1);
    define('JVERSION', '6.1.4');
    $root = sys_get_temp_dir() . '/nfs-preflight-' . bin2hex(random_bytes(8));
    mkdir($root . '/administrator/components', 0770, true);
    mkdir($root . '/libraries', 0770, true);
    define('JPATH_ADMINISTRATOR', $root . '/administrator');
    define('JPATH_LIBRARIES', $root . '/libraries');
    $script = require dirname(__DIR__) . '/src/pkg_nicode_form_studio/script.php';
    $script->database = new class {
        public array $tables = [];
        public function getTableList(): array { return $this->tables; }
        public function replacePrefix(string $value): string { return str_replace('#__', 'test_', $value); }
        public function getServerType(): string { return 'mysql'; }
        public function getVersion(): string { return '8.4.8'; }
    };
    $adapter = new \Joomla\CMS\Installer\InstallerAdapter();
    $check = static function (bool $reject) use ($script, $adapter): void {
        try { $script->preflight('install', $adapter); }
        catch (\RuntimeException $error) {
            if ($reject && str_contains($error->getMessage(), 'cannot upgrade')) { return; }
            throw $error;
        }
        if ($reject) { throw new \RuntimeException('Legacy installation was not blocked.'); }
    };
    try {
        $check(false);
        $script->database->tables = ['test_nicode_form_studio_forms', 'test_content']; $check(false);
        $script->database->tables = ['test_nicode_easyforms_forms']; $check(true);
        $script->database->tables = [];
        mkdir(JPATH_ADMINISTRATOR . '/components/com_nicode_easy_forms'); $check(true);
        rmdir(JPATH_ADMINISTRATOR . '/components/com_nicode_easy_forms');
        mkdir(JPATH_LIBRARIES . '/nicode_easy_forms'); $check(true);
        rmdir(JPATH_LIBRARIES . '/nicode_easy_forms');
        echo "5 rename preflight cases passed (isolated stubs; no live installation).\n";
    } finally {
        foreach ([JPATH_ADMINISTRATOR . '/components/com_nicode_easy_forms', JPATH_LIBRARIES . '/nicode_easy_forms', JPATH_ADMINISTRATOR . '/components', JPATH_ADMINISTRATOR, JPATH_LIBRARIES, $root] as $directory) {
            if (is_dir($directory)) { rmdir($directory); }
        }
    }
}
