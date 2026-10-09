<?php
declare(strict_types=1);

// Deliberately separate from the release pipeline: no release acceptance claim.
$root = dirname(__DIR__); $output = $root . '/build/development-package';
if (!is_dir($output)) { mkdir($output, 0770, true); }
$manifestPaths = [];
foreach (['lib_nicode_form_studio', 'com_nicode_form_studio', 'mod_nicode_form_studio', 'plg_task_nicode_form_studio', 'plg_extension_nicode_form_studio'] as $extension) {
    $source = $root . '/src/' . $extension;
    $manifest = str_starts_with($extension, 'plg_') ? 'nicode_form_studio' : $extension;
    if (!simplexml_load_file($source . '/' . $manifest . '.xml')) { throw new RuntimeException('Invalid extension manifest.'); }
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file->isFile() || $file->isLink()) { continue; }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
        $files[$relative] = $file->getPathname();
    }
    ksort($files, SORT_STRING);
    $zip = new ZipArchive(); $path = $output . '/' . $extension . '.zip';
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { throw new RuntimeException('Unable to create development archive.'); }
    foreach ($files as $relative => $file) { $zip->addFile($file, $relative); $zip->setMtimeName($relative, 1789776000); }
    $zip->addFile($root . '/LICENSE', 'LICENSE'); $zip->setMtimeName('LICENSE', 1789776000);
    if (!$zip->close()) { throw new RuntimeException('Unable to finish development archive.'); }
    $manifestPaths[$extension . '.zip'] = $path;
}
$package = new ZipArchive(); $target = $output . '/pkg_nicode_form_studio.zip';
if ($package->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { throw new RuntimeException('Unable to create package.'); }
$package->addFile($root . '/src/pkg_nicode_form_studio/pkg_nicode_form_studio.xml', 'pkg_nicode_form_studio.xml');
$package->setMtimeName('pkg_nicode_form_studio.xml', 1789776000);
$package->addFile($root . '/src/pkg_nicode_form_studio/script.php', 'script.php');
$package->setMtimeName('script.php', 1789776000);
foreach ($manifestPaths as $name => $path) { $package->addFile($path, 'packages/' . $name); $package->setMtimeName('packages/' . $name, 1789776000); }
if (!$package->close()) { throw new RuntimeException('Unable to finish package.'); }
file_put_contents($output . '/NOT_A_RELEASE.txt', "Development integration artifact only. Product acceptance is incomplete; see docs/IMPLEMENTATION_STATUS.md.\n");
require_once __DIR__ . '/package-audit.php';
$audit = auditDevelopmentPackage($root, $output);
file_put_contents($output . '/build-manifest.json', json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo "Development package built for isolated integration only. SHA-256: " . hash_file('sha256', $target) . "\n";
