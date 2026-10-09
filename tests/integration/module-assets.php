<?php
declare(strict_types=1);
use Joomla\CMS\WebAsset\{WebAssetManager, WebAssetRegistry};
use Nicode\FormStudio\Infrastructure\Joomla\ModuleAssets;

test('ES module import maps cover every transitive import under a Joomla subdirectory', function (): void {
    $directory = dirname(__DIR__, 2) . '/src/com_nicode_form_studio/media/js';
    $manager = new WebAssetManager(new WebAssetRegistry());
    ModuleAssets::register($manager, $directory, 'https://example.test/subsite/media/com_nicode_form_studio/js');
    foreach (glob($directory . '/*.js') as $file) {
        $item = $manager->getAsset('script', 'com_nicode_form_studio.module.' . basename($file, '.js'));
        same(true, $item->getOption('importmap'));
        same('https://example.test/subsite/media/com_nicode_form_studio/js/' . basename($file), $item->getOption('importmapName'));
        same($item->getOption('importmapName'), $item->getUri());
        same(hash_file('sha256', $file), $item->getVersion());
    }
});

test('upgrading an imported module changes its cache identity without editing the entry point', function (): void {
    $directory = dirname(__DIR__, 2) . '/build/module-assets-' . bin2hex(random_bytes(5)); mkdir($directory);
    file_put_contents($directory . '/admin.js', "import './translation-editor.js';");
    file_put_contents($directory . '/translation-editor.js', 'old translation module');
    $before = new WebAssetManager(new WebAssetRegistry());
    ModuleAssets::register($before, $directory, 'https://example.test/media/com_nicode_form_studio/js');
    file_put_contents($directory . '/translation-editor.js', 'updated translation module');
    $after = new WebAssetManager(new WebAssetRegistry());
    ModuleAssets::register($after, $directory, 'https://example.test/media/com_nicode_form_studio/js');
    foreach (['admin' => true, 'translation-editor' => false] as $module => $same) {
        same($same, $before->getAsset('script', 'com_nicode_form_studio.module.' . $module)->getVersion() === $after->getAsset('script', 'com_nicode_form_studio.module.' . $module)->getVersion());
    }
});
