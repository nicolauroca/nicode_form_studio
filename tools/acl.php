<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/lib_nicode_form_studio/autoload.php';
$xml = new SimpleXMLElement('<access/>');
foreach (['component', 'form'] as $scope) {
    $section = $xml->addChild('section'); $section->addAttribute('name', $scope);
    foreach (Nicode\FormStudio\Security\Permissions::ALL as $permission) {
        if ($scope === 'form' && in_array($permission, ['core.admin', 'core.options', 'formstudio.resources.manage'], true)) { continue; }
        $action = $section->addChild('action'); $action->addAttribute('name', $permission); $action->addAttribute('title', 'COM_NICODE_FORM_STUDIO_PERMISSION_' . strtoupper(str_replace('.', '_', $permission)));
    }
}
$document = dom_import_simplexml($xml)->ownerDocument; $document->formatOutput = true;
file_put_contents(dirname(__DIR__) . '/src/com_nicode_form_studio/administrator/access.xml', $document->saveXML());
echo "ACL manifest regenerated.\n";
