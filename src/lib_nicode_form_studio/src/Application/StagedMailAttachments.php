<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Application;

use Nicode\FormStudio\Actions\{ActionContext, ActionFailure, MailAttachment};
use Nicode\FormStudio\Contract\MailAttachmentResolverInterface;
use Nicode\FormStudio\Domain\{FormSpec, RepeatedInstances, Uuid};
use Nicode\FormStudio\Registry\StorageProviderRegistry;
use Nicode\FormStudio\Storage\StoredFile;

/** Request-local capability built exclusively from validated upload gateway results. */
final readonly class StagedMailAttachments implements MailAttachmentResolverInterface
{
    public function __construct(private StorageProviderRegistry $storage, private FormSpec $spec, private string $reference, private array $values, private ?array $instances, private array $files) {}

    public function resolve(array $selectedFields, ActionContext $context): array
    {
        try { return $this->read($selectedFields, $context); }
        catch (\Throwable) { throw new ActionFailure('mail_attachment_unavailable'); }
    }

    private function read(array $selectedFields, ActionContext $context): array
    {
        if (!array_is_list($selectedFields) || count($selectedFields)>20 || array_filter($selectedFields, static fn($uuid): bool => !Uuid::valid($uuid))!==[] || count(array_unique($selectedFields))!==count($selectedFields)) { throw new \DomainException('Invalid attachment selection.'); }
        if ($selectedFields===[]) { return []; }
        if (!Uuid::valid($this->reference) || $context->reference!==$this->reference || !hash_equals($this->spec->hash,$context->spec->hash)) { throw new \DomainException('Attachment context mismatch.'); }
        $definition=$this->spec->toArray(); $fields=array_column($definition['fields'],null,'uuid'); $addresses=[];
        if (in_array('repeatable-group',array_column($definition['elements'],'type'),true)!==($this->instances!==null)) { throw new \DomainException('Invalid attachment scope.'); }
        if ($this->instances!==null) {
            $instances=new RepeatedInstances($definition['elements'],$this->instances); $instances->bind($this->values);
            foreach ($instances->addresses() as $address) { $addresses[$address->field][]=$address->key(); }
        } else { foreach ($fields as $uuid=>$field) { $addresses[$uuid]=[$uuid]; } }
        $available=[];
        foreach ($this->files as $entry) {
            $identity=$entry['receipt']['uuid'] ?? null;
            if (!Uuid::valid($identity) || isset($available[$identity]) || !($entry['file'] instanceof StoredFile)) { throw new \DomainException('Invalid upload capability.'); }
            $available[$identity]=$entry;
        }
        $attachments=[]; $seen=[]; $total=0;
        foreach ($selectedFields as $uuid) {
            if (!isset($fields[$uuid]) || !in_array($fields[$uuid]['type'],['file','multiple-files'],true) || !($fields[$uuid]['include_email'] ?? !($fields[$uuid]['sensitive'] ?? false))) { throw new \DomainException('Attachment field excluded.'); }
            foreach ($addresses[$uuid] ?? [] as $key) {
                $value=$this->values[$key] ?? null;
                if ($value===null || $value===[]) { continue; }
                if (!is_array($value)) { throw new \DomainException('Invalid receipt.'); }
                foreach (array_is_list($value)?$value:[$value] as $receipt) {
                    $identity=$receipt['uuid'] ?? null;
                    if (!Uuid::valid($identity) || isset($seen[$identity]) || count($attachments)>=20 || !isset($available[$identity])) { throw new \DomainException('Unavailable receipt.'); }
                    $entry=$available[$identity]; $file=$entry['file'];
                    if (($entry['field_address'] ?? null)!==$key || \Nicode\FormStudio\Domain\CanonicalJson::encode($entry['receipt'])!==\Nicode\FormStudio\Domain\CanonicalJson::encode($receipt) || ($receipt['size'] ?? null)!==$file->size || $file->size<0 || $file->size>MailAttachment::MAXIMUM_BYTES-$total) { throw new \DomainException('Upload ownership mismatch.'); }
                    $stream=$this->storage->get($file->provider)->open($file->key);
                    try { $bytes=stream_get_contents($stream,$file->size+1); }
                    finally { fclose($stream); }
                    if ($bytes===false || strlen($bytes)!==$file->size || !hash_equals($file->checksum,hash('sha256',$bytes))) { throw new \DomainException('Upload integrity mismatch.'); }
                    $attachments[]=new MailAttachment($receipt['name'],$receipt['mime'],$bytes);
                    $seen[$identity]=true; $total+=$file->size;
                }
            }
        }
        return $attachments;
    }
}
