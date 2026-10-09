<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Application;

use Nicode\FormStudio\Compiler\{CompilationException, FormCompiler};
use Nicode\FormStudio\Contract\CaptchaAdapterInterface;
use Nicode\FormStudio\Domain\FormSpec;
use Nicode\FormStudio\Infrastructure\Database\FormRepository;
use Nicode\FormStudio\Registry\ProviderDependencies;
use Nicode\FormStudio\Security\{CaptchaException, CaptchaPolicy};

/** Read-only checks: no deliveries, option lookups, public tokens or raw errors. */
final readonly class FormDiagnostics
{
    public function __construct(private FormRepository $forms, private FormCompiler $compiler, private CaptchaAdapterInterface $captcha, private PublicationReadiness $readiness, private ProviderDependencies $dependencies) {}

    public function inspect(int $id, ?int $version): array
    {
        $flags = ['invalid' => false, 'warning' => false, 'action' => false, 'captcha' => false, 'provider' => false, 'unavailable' => false];
        try {
            $result = $this->compiler->compile($this->forms->draft($id));
            $flags['invalid'] = !$result->successful();
            $this->diagnostics($result->diagnostics, $flags);
            if ($result->spec !== null) { $this->deployment($result->spec, $flags); }
            if ($version !== null) {
                $published = $this->forms->version($id, $version);
                $publishedResult = $this->compiler->compile($published->toArray());
                $flags['invalid'] = $flags['invalid'] || !$publishedResult->successful();
                $this->diagnostics($publishedResult->diagnostics, $flags);
                try { $this->dependencies->assert($published); }
                catch (\DomainException) { $flags['provider'] = true; }
                $this->deployment($published, $flags);
            }
        } catch (\Throwable) { $flags['unavailable'] = true; }
        return $flags;
    }

    private function deployment(FormSpec $spec, array &$flags): void
    {
        $policy = $spec->toArray()['security']['captcha'] ?? [];
        try { $this->captcha->assertAvailable(new CaptchaPolicy($policy['mode'] ?? 'inherit', $policy['provider'] ?? null)); }
        catch (CaptchaException) { $flags['captcha'] = true; }
        try { $this->readiness->assert($spec); }
        catch (CompilationException $error) { $this->diagnostics($error->diagnostics, $flags); $flags['invalid'] = true; }
    }

    private function diagnostics(array $diagnostics, array &$flags): void
    {
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->severity === 'WARNING') { $flags['warning'] = true; }
            if (str_starts_with($diagnostic->path, '/actions/')) { $flags['action'] = true; }
            if (str_starts_with($diagnostic->code, 'provider.') || $diagnostic->code === 'publication.renderer') { $flags['provider'] = true; }
        }
    }
}
