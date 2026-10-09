<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Security;

use Nicode\FormStudio\Contract\SecretStoreInterface;

/** Deployment-managed secrets stay outside FormSpec and component parameters. */
final class EnvironmentSecrets implements SecretStoreInterface
{
    public function get(string $reference): string
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{0,100}$/D', $reference) !== 1) { throw new \DomainException('Secret unavailable.'); }
        $value = getenv('NICODE_FORMSTUDIO_SECRET_' . $reference);
        if (!is_string($value) || $value === '' || strlen($value) > 8192) { throw new \DomainException('Secret unavailable.'); }
        return $value;
    }
}
