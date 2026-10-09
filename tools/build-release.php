<?php
declare(strict_types=1);

// OPS-008 itself closes after this artifact has passed native release acceptance.
$root = dirname(__DIR__);
$requirements = json_decode(file_get_contents($root . '/docs/requirements-status.json'), true, flags: JSON_THROW_ON_ERROR);
if (count($requirements) !== 133) { throw new RuntimeException('Release requires the complete requirement inventory.'); }
foreach ($requirements as $id => $requirement) {
    if ($id !== 'OPS-008' && ($requirement['status'] ?? null) !== 'COMPLETE') { throw new RuntimeException('Release acceptance incomplete: ' . $id); }
}
$manifest = simplexml_load_file($root . '/src/pkg_nicode_form_studio/pkg_nicode_form_studio.xml', SimpleXMLElement::class, LIBXML_NONET);
$releaseVersion = (string) $manifest->version;
if (preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $releaseVersion) !== 1) { throw new RuntimeException('Set a stable source-manifest version before building a release.'); }
foreach (['ADMIN_USER_GUIDE.md', 'BUILD_AND_RELEASE.md', 'DEVELOPER_GUIDE.md'] as $document) {
    if (!is_file($root . '/docs/' . $document)) { throw new RuntimeException('Missing release documentation: ' . $document); }
}
require __DIR__ . '/build-development.php';
$releaseDirectory = $root . '/dist';
if (!is_dir($releaseDirectory) && !mkdir($releaseDirectory, 0770, true)) { throw new RuntimeException('Unable to create release directory.'); }
$releaseName = 'pkg_nicode_form_studio-' . $releaseVersion . '.zip';
$releasePath = $releaseDirectory . '/' . $releaseName;
if (is_file($releasePath) && !hash_equals(hash_file('sha256', $target), hash_file('sha256', $releasePath))) { throw new RuntimeException('Refusing to overwrite a different versioned release artifact.'); }
if (!copy($target, $releasePath) || !hash_equals(hash_file('sha256', $target), hash_file('sha256', $releasePath))) { throw new RuntimeException('Release copy integrity failed.'); }
$releaseAudit = $audit;
$releaseAudit['release'] = true;
$releaseAudit['artifact'] = $releaseName;
$releaseAudit['sha256'] = hash_file('sha256', $releasePath);
$releaseAudit['toolchain'] = ['php' => PHP_VERSION, 'libzip' => ZipArchive::LIBZIP_VERSION, 'zlib' => phpversion('zlib')];
$releaseAudit['acceptance'] = ($requirements['OPS-008']['status'] ?? null) === 'COMPLETE'
    ? 'Release acceptance recorded in docs/REQUIREMENT_ACCEPTANCE.md, including explicit user acceptance of accessibility.'
    : 'Product requirements complete; OPS-008 closes after native final-artifact acceptance.';
file_put_contents($releasePath . '.sha256', $releaseAudit['sha256'] . '  ' . $releaseName . "\n");
file_put_contents($releaseDirectory . '/release-' . $releaseVersion . '.json', json_encode($releaseAudit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo "Versioned package built: " . $releaseName . "\n";
