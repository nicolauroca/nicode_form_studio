<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Transfer;

use Nicode\FormStudio\Compiler\FormCompiler;
use Nicode\FormStudio\Domain\{CanonicalJson, SpecDiff};

/** Pure analysis: no persistence, publication or provider execution. */
final readonly class ImportPreview
{
    public function __construct(private DefinitionPackage $packages, private FormCompiler $compiler) {}

    /** The caller resolves an existing definition only after checking its edit permission. */
    public function analyze(string $json, string $policy, ?array $existing = null): array
    {
        if (!in_array($policy, ['duplicate', 'update', 'conflict'], true)) { throw new \InvalidArgumentException('Invalid UUID collision policy.'); }
        $package = $this->packages->decode($json); $definition = $package['definition'];
        $conflicts = [];
        if ($existing !== null && ($existing['uuid'] ?? null) !== $definition['uuid']) { throw new \InvalidArgumentException('Update target identity does not match the package.'); }
        if ($policy === 'update' && $existing === null) { $conflicts[] = ['code' => 'import.target_missing', 'path' => '/uuid']; }
        if ($policy === 'conflict' && $existing !== null) { $conflicts[] = ['code' => 'import.uuid_collision', 'path' => '/uuid']; }
        $result = $this->compiler->compile($definition);
        $diagnostics = array_map(static fn ($diagnostic): array => $diagnostic->jsonSerialize(), $result->diagnostics);
        $references = [];
        foreach ($definition['fields'] as $index => $field) {
            if (($field['source']['type'] ?? '') !== 'option_set') { continue; }
            $configuration = $field['source']['config'];
            $references[] = ['path' => '/fields/' . $index . '/source', 'resource_uuid' => $configuration['resource_uuid'] ?? null, 'revision' => $configuration['revision'] ?? null, 'resource_hash' => $configuration['resource_hash'] ?? null, 'embedded_options_hash' => hash('sha256', CanonicalJson::encode($configuration['options'] ?? []))];
        }
        return [
            'package' => $package, 'policy' => $policy, 'conflicts' => $conflicts,
            'diagnostics' => $diagnostics, 'references' => $references,
            'comparison' => $existing !== null && $policy === 'update' ? (new SpecDiff())->compare($existing, $definition) : null,
            'counts' => array_map(count(...), array_intersect_key($definition, array_flip(['elements', 'fields', 'rules', 'actions']))),
            // Semantic errors are editable in a draft; structural/security failures throw above.
            'can_import_draft' => $conflicts === [], 'definition_valid' => $result->successful(),
            'requires_review' => $package['review'] !== [] || $references !== [],
            'identity_remap' => $policy === 'duplicate',
        ];
    }
}
