<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Contract;

/** Implemented by trusted installed providers, never by editable definitions. */
interface BrowserProviderInterface extends ProviderInterface
{
    /** @return array{asset:string, public_config:list<string>, styles?:list<string>} */
    public function browser(): array;
}
