<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Field;

use Nicode\FormStudio\Contract\FieldTypeInterface;
use Nicode\FormStudio\Domain\Diagnostic;
use Nicode\FormStudio\Domain\Uuid;
use Nicode\FormStudio\Storage\UploadPolicy;

/** Only trusted upload receipts reach this provider through ValidationEngine. */
final readonly class FileFieldType implements FieldTypeInterface
{
    public function __construct(private bool $many = false) {}
    public function id(): string { return $this->many ? 'multiple-files' : 'file'; }
    public function version(): string { return '1.0.0'; }
    public function indexType(): ?string { return null; }
    public function multiple(): bool { return $this->many; }
    public function metadata(): array
    {
        return ['id' => $this->id(), 'label_key' => 'COM_NICODE_FORM_STUDIO_FIELD_TYPE_' . strtoupper(str_replace('-', '_', $this->id())), 'version' => $this->version(), 'datatype' => 'file', 'index_type' => null, 'multiple' => $this->many, 'category' => 'file', 'renderer' => $this->id(), 'prefill' => false, 'operators' => ['empty', 'not_empty'], 'assets' => [], 'configuration_schema' => ['type' => 'object', 'properties' => CommonConfiguration::properties() + [
            'extensions' => ['type' => 'array', 'items' => ['type' => 'string']],
            'mime_types' => ['type' => 'array', 'items' => ['type' => 'string']],
            'max_bytes' => ['type' => 'integer', 'minimum' => 1],
            'max_files' => ['type' => 'integer', 'minimum' => 1, 'maximum' => $this->many ? 100 : 1],
        ]]];
    }
    public function validateConfiguration(array $configuration, string $path): array
    {
        try {
            self::policy($configuration, $this->many);
            if (isset($configuration['default'])) { throw new \InvalidArgumentException('File fields cannot have defaults.'); }
        } catch (\InvalidArgumentException) { return [new Diagnostic('field.file_policy', $path, 'File fields require explicit safe extensions, MIME types, size and count limits, and no default.')]; }
        return [];
    }
    public static function policy(array $configuration, bool $many): UploadPolicy
    {
        if (!is_array($configuration['extensions'] ?? null) || !is_array($configuration['mime_types'] ?? null) || !is_int($configuration['max_bytes'] ?? null) || !is_int($configuration['max_files'] ?? 1)) { throw new \InvalidArgumentException('Invalid upload policy.'); }
        if (!$many && ($configuration['max_files'] ?? 1) !== 1) { throw new \InvalidArgumentException('Single file count must be one.'); }
        return new UploadPolicy($configuration['extensions'], $configuration['mime_types'], $configuration['max_bytes'], $configuration['max_files'] ?? 1);
    }
    public function normalize(mixed $value, array $configuration): mixed
    {
        if ($value === null || $value === []) { return $this->many ? [] : null; }
        $receipts = $this->many ? $value : [$value];
        if (!is_array($receipts) || !array_is_list($receipts)) { throw new \InvalidArgumentException('Expected upload receipts.'); }
        $result = []; $seen = [];
        foreach ($receipts as $receipt) {
            if (!is_array($receipt) || !Uuid::valid($receipt['uuid'] ?? null) || isset($seen[$receipt['uuid']]) || !is_string($receipt['name'] ?? null) || !is_string($receipt['mime'] ?? null) || !is_int($receipt['size'] ?? null) || $receipt['size'] < 0) { throw new \InvalidArgumentException('Invalid upload receipt.'); }
            $seen[$receipt['uuid']] = true;
            $result[] = array_intersect_key($receipt, array_flip(['uuid', 'name', 'mime', 'size']));
        }
        return $this->many ? $result : $result[0];
    }
    public function validate(mixed $value, array $configuration): array
    {
        $receipts = $this->many ? $value : ($value === null ? [] : [$value]);
        if ($receipts === []) { return ($configuration['required'] ?? false) ? ['required'] : []; }
        $policy = self::policy($configuration, $this->many); $errors = [];
        if (count($receipts) > $policy->maxFiles) { $errors[] = 'file_count'; }
        foreach ($receipts as $receipt) {
            if ($receipt['size'] > $policy->maxBytes) { $errors[] = 'file_size'; }
            if (!in_array($receipt['mime'], $policy->mimeTypes, true) || !in_array(strtolower(pathinfo($receipt['name'], PATHINFO_EXTENSION)), $policy->extensions, true)) { $errors[] = 'file_type'; }
        }
        return array_values(array_unique($errors));
    }
    public function serialize(mixed $value): mixed { return $value; }
}
