<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Application;

use Nicode\FormStudio\Actions\{ActionContext, ActionFailure, MailAttachment};
use Nicode\FormStudio\Domain\{RepeatedInstances, Uuid};
use Nicode\FormStudio\Infrastructure\Database\Connection;
use Nicode\FormStudio\Registry\StorageProviderRegistry;
use Nicode\FormStudio\Submission\StoredFileAddress;

/** Resolves only persisted, original-version file receipts authorized for email. */
final readonly class StoredMailAttachments implements \Nicode\FormStudio\Contract\MailAttachmentResolverInterface
{
    public function __construct(private Connection $db, private StorageProviderRegistry $storage) {}

    /** @return list<MailAttachment> */
    public function resolve(array $selectedFields, ActionContext $context): array
    {
        try { return $this->read($selectedFields, $context); }
        catch (\Throwable) { throw new ActionFailure('mail_attachment_unavailable'); }
    }

    private function read(array $selectedFields, ActionContext $context): array
    {
        if (!array_is_list($selectedFields) || count($selectedFields) > 20 || count(array_unique($selectedFields, SORT_REGULAR)) !== count($selectedFields)) { throw new \InvalidArgumentException('Invalid attachment selection.'); }
        if ($selectedFields === []) { return []; }
        if (!Uuid::valid($context->reference)) { throw new \InvalidArgumentException('Invalid response identity.'); }
        $row=$this->db->row('SELECT s.id, s.canonical_payload, s.anonymized_at, v.hash FROM '.$this->db->table('submissions').' s JOIN '.$this->db->table('form_versions').' v ON v.id=s.form_version_id WHERE s.uuid=:uuid',[':uuid'=>$context->reference]);
        if (!$row || $row['anonymized_at'] !== null || !hash_equals($row['hash'],$context->spec->hash)) { throw new \DomainException('Response snapshot unavailable.'); }
        $definition=$context->spec->toArray(); $fields=array_column($definition['fields'],null,'uuid');
        $payload=json_decode($row['canonical_payload'],true,512,JSON_THROW_ON_ERROR);
        $values=$payload['values']; $addresses=[];
        if (!is_array($values) || in_array('repeatable-group',array_column($definition['elements'],'type'),true)!==array_key_exists('instances',$payload)) { throw new \DomainException('Invalid canonical attachment scope.'); }
        if (array_key_exists('instances',$payload)) {
            $instances=new RepeatedInstances($definition['elements'],$payload['instances']); $instances->bind($values);
            foreach ($instances->addresses() as $address) { $addresses[$address->field][]=$address->key(); }
        } else {
            foreach ($fields as $uuid=>$field) { $addresses[$uuid]=[$uuid]; }
        }
        $attachments=[]; $seen=[]; $total=0;
        foreach ($selectedFields as $uuid) {
            if (!Uuid::valid($uuid) || !isset($fields[$uuid]) || !in_array($fields[$uuid]['type'],['file','multiple-files'],true) || !($fields[$uuid]['include_email'] ?? !($fields[$uuid]['sensitive'] ?? false))) { throw new \DomainException('Attachment field excluded.'); }
            // Omitted ephemeral data cannot prove that the original message had no files.
            if (($definition['persistence']['mode'] ?? 'full')!=='full' || !($fields[$uuid]['persist'] ?? true)) { throw new \DomainException('Attachment snapshot was not retained.'); }
            foreach ($addresses[$uuid] ?? [] as $key) {
                $value=$values[$key] ?? null;
                if ($value === null || $value === []) { continue; }
                if (!is_array($value)) { throw new \DomainException('Invalid file receipt.'); }
                $receipts=array_is_list($value)?$value:[$value];
                foreach ($receipts as $receipt) {
                    $identity=$receipt['uuid'] ?? null;
                    if (!Uuid::valid($identity) || isset($seen[$identity]) || count($attachments) >= 20) { throw new \DomainException('Invalid attachment identity/count.'); }
                    $file=$this->db->row('SELECT * FROM '.$this->db->table('submission_files').' WHERE submission_id=:submission AND uuid=:uuid',[':submission'=>(int)$row['id'],':uuid'=>$identity]);
                    if (!$file || StoredFileAddress::key($file)!==$key || !StoredFileAddress::matches($file,$values)) { throw new \DomainException('File ownership mismatch.'); }
                    $size=(int)$file['size_bytes'];
                    if ($size < 0 || $size > MailAttachment::MAXIMUM_BYTES-$total || ($receipt['size'] ?? null)!==$size || ($receipt['name'] ?? null)!==$file['original_name'] || ($receipt['mime'] ?? null)!==$file['mime']) { throw new \DomainException('File receipt metadata mismatch.'); }
                    $stream=$this->storage->get($file['provider'])->open($file['storage_key']);
                    try { $bytes=stream_get_contents($stream,$size+1); }
                    finally { fclose($stream); }
                    if ($bytes===false || strlen($bytes)!==$size || !hash_equals($file['checksum'],hash('sha256',$bytes))) { throw new \DomainException('File integrity mismatch.'); }
                    $attachments[]=new MailAttachment($file['original_name'],$file['mime'],$bytes);
                    $total+=$size; $seen[$identity]=true;
                }
            }
        }
        return $attachments;
    }
}
