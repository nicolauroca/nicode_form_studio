<?php
declare(strict_types=1);

$root = dirname(__DIR__) . '/docs';
$files = glob($root . '/[0-9][0-9]_*.md');
sort($files, SORT_STRING);
$master = "# Nicode Form Studio — MASTER SPEC\n> Documento agregado para consulta integral. Los documentos temáticos de `docs/` siguen siendo la fuente normativa por materia.\n";
foreach ($files as $file) {
    $master .= "\n\n---\n\n<!-- SOURCE: " . basename($file) . " -->\n\n" . rtrim(str_replace("\r\n", "\n", file_get_contents($file))) . "\n";
}
file_put_contents($root . '/MASTER_SPEC.md', $master);
$files = array_merge(glob($root . '/*.md'), glob($root . '/adr/*.md'));
sort($files, SORT_STRING);
$manifest = "# Documentation Manifest\n\nGenerated with `php tools/docs.php`. SHA-256 covers exact file bytes.\n\n| File | Lines | SHA-256 |\n|---|---:|---|\n";
foreach ($files as $file) {
    if (basename($file) === 'MANIFEST.md') {
        continue;
    }
    $manifest .= '| `' . substr($file, strlen($root) + 1) . '` | ' . count(file($file)) . ' | `' . hash_file('sha256', $file) . "` |\n";
}
file_put_contents($root . '/MANIFEST.md', $manifest);
echo "Documentation aggregate and manifest rebuilt.\n";
