<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Rendering;

use Nicode\FormStudio\Contract\BrowserProviderInterface;
use Nicode\FormStudio\Registry\ProviderRegistry;

final readonly class BrowserProviders
{
    private array $coreFields;
    /** @param array<string, ProviderRegistry> $registries */
    public function __construct(private array $registries, private \Closure $resolveAsset)
    {
        $core = new \Nicode\FormStudio\Registry\FieldTypeRegistry();
        \Nicode\FormStudio\Field\CoreFieldTypes::register($core);
        $this->coreFields = array_fill_keys(array_keys($core->metadata()), true);
    }

    public function descriptor(string $kind, string $id): ?array
    {
        $provider = ($this->registries[$kind] ?? throw new \DomainException('Browser registry unavailable.'))->get($id);
        $core = match ($kind) {
            'fields' => isset($this->coreFields[$id]),
            'operators' => in_array($id, \Nicode\FormStudio\Rules\CoreOperator::IDS, true),
            'effects' => in_array($id, \Nicode\FormStudio\Compiler\FormCompiler::EFFECTS, true),
            'validators' => in_array($id, \Nicode\FormStudio\Validation\RelationalValidator::IDS, true),
            default => throw new \DomainException('Invalid browser registry.'),
        };
        if ($core) { return null; }
        if (!$provider instanceof BrowserProviderInterface) {
            if ($kind === 'validators') { return null; }
            throw new \DomainException('Required browser provider unavailable: ' . $id);
        }
        $contract = $provider->browser();
        if (!is_string($contract['asset'] ?? null) || preg_match('/^[a-zA-Z0-9_.-]+$/D', $contract['asset']) !== 1 || !is_array($contract['public_config'] ?? null) || !array_is_list($contract['public_config'])) { throw new \DomainException('Invalid browser provider contract.'); }
        foreach ($contract['public_config'] as $key) { if (!is_string($key) || preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D', $key) !== 1) { throw new \DomainException('Invalid public configuration key.'); } }
        $url = ($this->resolveAsset)($contract['asset'], 'script');
        if (!is_string($url) || $url === '') { throw new \DomainException('Required browser asset unavailable.'); }
        $styles = $contract['styles'] ?? [];
        if (!is_array($styles) || !array_is_list($styles)) { throw new \DomainException('Invalid browser styles.'); }
        $resolved = [];
        foreach ($styles as $style) {
            if (!is_string($style) || preg_match('/^[a-zA-Z0-9_.-]+$/D', $style) !== 1) { throw new \DomainException('Invalid browser style asset.'); }
            $uri = ($this->resolveAsset)($style, 'style');
            if (!is_string($uri) || $uri === '') { throw new \DomainException('Required browser style unavailable.'); }
            $resolved[] = $uri;
        }
        return ['version' => $provider->version(), 'module' => $url, 'public_config' => $contract['public_config'], ...($resolved === [] ? [] : ['styles' => array_values(array_unique($resolved))])];
    }

    public static function configuration(array $configuration, ?array $descriptor): array
    {
        return array_intersect_key($configuration, array_flip($descriptor['public_config'] ?? []));
    }
}
