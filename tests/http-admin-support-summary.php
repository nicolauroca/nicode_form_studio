<?php
declare(strict_types=1);
$healthPage = $request($base . '?option=com_nicode_form_studio&view=health');
$healthDocument = new DOMDocument(); $healthPrior = libxml_use_internal_errors(true); $healthDocument->loadHTML($healthPage['body']); libxml_clear_errors(); libxml_use_internal_errors($healthPrior);
$summaryNode = (new DOMXPath($healthDocument))->query('//textarea[@id="nfs-support-summary"]')->item(0);
$assert($summaryNode instanceof DOMElement && $summaryNode->hasAttribute('readonly') && $summaryNode->getAttribute('aria-describedby') === 'nfs-support-help', 'Native support summary is missing its readonly/labelled help control.');
$support = json_decode($summaryNode->textContent, true, flags: JSON_THROW_ON_ERROR);
$assert($support['format'] === 'nicode-formstudio-support-v1' && isset($support['checks']['database']['status'], $support['versions']['package:pkg_nicode_form_studio'], $support['php_limits']['post_max_size']), 'Native support summary lost required safe fields.');
$assert(!str_contains($summaryNode->textContent, $root) && !str_contains($summaryNode->textContent, 'manifest_cache') && !str_contains($summaryNode->textContent, 'canonical_payload'), 'Native support summary exposed internal paths or raw records.');
echo "Native support summary: labelled readonly JSON, known versions/checks/limits and raw-record exclusion passed.\n";
