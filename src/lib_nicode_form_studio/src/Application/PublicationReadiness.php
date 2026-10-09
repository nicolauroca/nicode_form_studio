<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Application;

use Nicode\FormStudio\Compiler\CompilationException;
use Nicode\FormStudio\Contract\SecretStoreInterface;
use Nicode\FormStudio\Domain\{Diagnostic, FormSpec};
use Nicode\FormStudio\Registry\StorageProviderRegistry;

/** Checks deployment prerequisites without performing outbound deliveries. */
final readonly class PublicationReadiness
{
    public function __construct(private StorageProviderRegistry $storage, private SecretStoreInterface $secrets, private \Closure $mailConfigured, private ?\Nicode\FormStudio\Rendering\FieldRendererRegistry $renderers = null, private ?\Nicode\FormStudio\Rendering\PublicSpec $publicSpec = null) {}

    public function assert(FormSpec $spec): void
    {
        $definition = $spec->toArray(); $errors = [];
        try { $this->publicSpec?->project($spec); }
        catch (\Throwable) { $errors[] = new Diagnostic('publication.browser', '/provider_dependencies', 'A required browser provider or module asset is unavailable.'); }
        foreach ($definition['fields'] as $i => $field) {
            if ($this->renderers !== null && !$this->renderers->has($field['type'])) { $errors[] = new Diagnostic('publication.renderer', '/fields/' . $i, 'The field provider has no registered renderer.'); }
            if (in_array($field['type'], ['file', 'multiple-files'], true) && (!$this->storage->has('local') || ($this->storage->get('local')->metadata()['available'] ?? true) === false)) { $errors[] = new Diagnostic('publication.storage', '/fields/' . $i, 'Configure private upload storage before publishing file fields.'); }
        }
        foreach ($definition['actions'] as $i => $action) {
            if (!($action['enabled'] ?? true)) { continue; }
            if (in_array($action['type'], ['email_notification', 'email_autoresponse'], true) && !(($this->mailConfigured)())) { $errors[] = new Diagnostic('publication.mail', '/actions/' . $i, 'Enable Joomla mail and configure a valid sender before publishing email actions.'); }
            if ($action['type'] !== 'webhook') { continue; }
            foreach (['bearer_secret', 'signing_secret'] as $key) {
                if (!isset($action['config'][$key])) { continue; }
                try { $this->secrets->get($action['config'][$key]); }
                catch (\Throwable) { $errors[] = new Diagnostic('publication.secret', '/actions/' . $i . '/config/' . $key, 'The referenced server secret is unavailable.'); }
            }
        }
        if ($errors !== []) { throw new CompilationException($errors); }
    }
}
