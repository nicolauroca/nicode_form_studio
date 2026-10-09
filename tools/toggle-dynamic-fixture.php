<?php
declare(strict_types=1);
// Fault injection for the authored provider in the isolated browser acceptance site.
$root = dirname(__DIR__);
require $root . '/build/joomla-6.0.0/configuration.php';
$config = new JConfig();
if ($config->db !== 'formstudio_joomla' || $config->host !== '127.0.0.1:13367' || $config->dbprefix !== 'j6_') {
    throw new RuntimeException('Refusing a non-isolated provider fixture change.');
}
$enabled = match ($argv[1] ?? '') { 'enable' => 1, 'disable' => 0, default => throw new InvalidArgumentException('Use enable or disable.') };
$pdo = new PDO('mysql:host=127.0.0.1;port=13367;dbname=formstudio_joomla;charset=utf8mb4', $config->user, $config->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$query = $pdo->prepare("UPDATE j6_extensions SET enabled = ? WHERE type = 'plugin' AND folder = 'formstudio' AND element = 'providerfixture'");
$query->execute([$enabled]);
echo 'Isolated provider fixture ' . ($enabled ? 'enabled' : 'disabled') . ".\n";
