<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Submission;

use Nicode\FormStudio\Domain\{FormSpec, RepeatedInstances};
use Nicode\FormStudio\Validation\ValidationResult;

/** Persistence policy shared by ordinary and addressed canonical values. */
final class StoredValues
{
    public static function select(array $definition, array $values, int $version, string $receivedAt, array $optionLabels = []): array
    {
        $mode = $definition['persistence']['mode'] ?? 'full';
        if (!in_array($mode, ['full', 'metadata', 'none'], true)) { throw new \InvalidArgumentException('Unsupported persistence mode.'); }
        $stored = []; $consents = [];
        foreach ($definition['fields'] as $field) {
            $key = $field['uuid'];
            if ($mode !== 'full' || !array_key_exists($key, $values) || !($field['persist'] ?? true) || $field['type'] === 'password') { continue; }
            $stored[$key] = $values[$key];
            if ($field['type'] === 'consent') { $consents[$key] = ['accepted'=>$values[$key] === true,'text'=>$field['config']['label'] ?? '', 'form_version_id'=>$version,'received_at'=>$receivedAt]; }
        }
        return ['values'=>$stored,'consents'=>$consents,'option_labels'=>array_intersect_key($optionLabels,$stored)];
    }

    /** Canonical fragment consumed by the addressed repository entry point. */
    public static function instances(FormSpec $spec, array $declarations, ValidationResult $validated, int $version, string $receivedAt, array $optionLabels = [], string $locale = 'en-GB', int $budget = 10000): array
    {
        if (!$validated->valid()) { throw new \InvalidArgumentException('Cannot persist invalid instance values.'); }
        $definition = \Nicode\FormStudio\Translation\DefinitionTranslations::resolve($spec->toArray(), $locale);
        $instances = new RepeatedInstances($definition['elements'], $declarations, $budget);
        $instances->bind($validated->values, $budget);
        foreach ($instances->elementAddresses($budget) as $address) {
            if (!is_bool($validated->rules->states[$address->key()]['active'] ?? null)) { throw new \InvalidArgumentException('Missing authoritative instance state.'); }
        }
        foreach ($validated->values as $key => $_) {
            if (!$validated->rules->states[$key]['active']) { throw new \InvalidArgumentException('Inactive value in persistence input.'); }
        }
        foreach ($instances->minimumErrors() as $key => $_) {
            if ($validated->rules->states[$key]['active']) { throw new \InvalidArgumentException('Active group minimum is not satisfied.'); }
        }
        $fields = array_column($definition['fields'],null,'uuid'); $expanded = [];
        foreach ($instances->addresses($budget) as $address) {
            $field = $fields[$address->field] ?? throw new \InvalidArgumentException('Missing field definition.');
            $field['uuid'] = $address->key(); $expanded[] = $field;
        }
        $definition['fields'] = $expanded;
        $payload = self::select($definition,$validated->values,$version,$receivedAt,$optionLabels);
        $payload['instances'] = [];
        if (($definition['persistence']['mode'] ?? 'full') === 'full') {
            foreach ($instances->declarations() as $key => $rows) {
                if ($validated->rules->states[$key]['active']) { $payload['instances'][$key] = $rows; }
            }
        }
        return $payload;
    }
}
