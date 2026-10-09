<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Submission;

use Nicode\FormStudio\Domain\{CanonicalJson, FieldAddress, FormSpec, RepeatedInstances};
use Nicode\FormStudio\Storage\StoredFile;
use Nicode\FormStudio\Validation\ValidationResult;

/** HMAC input identity; plaintext action-only values never become stored payload. */
final readonly class RequestFingerprint
{
    public function __construct(private string $key)
    {
        if (strlen($key) < 32) { throw new \InvalidArgumentException('Fingerprint key is too short.'); }
    }

    public function ordinary(int $version, FormSpec $spec, array $values, array $context = []): string
    {
        $expected = array_intersect_key($values, array_flip(array_column($spec->toArray()['fields'], 'uuid')));
        return $this->hash($version, $this->files($expected,$context), $context);
    }

    public function instances(int $version, FormSpec $spec, array $declarations, ValidationResult $validated, array $context = [], int $budget = 10000): string
    {
        // Reuse the persistence boundary's state, membership and minimum checks.
        StoredValues::instances($spec,$declarations,$validated,$version,'',[], $context['locale'] ?? 'en-GB',$budget);
        $instances = new RepeatedInstances($spec->toArray()['elements'],$declarations,$budget);
        $active = [];
        foreach ($instances->declarations() as $address => $rows) {
            if ($validated->rules->states[$address]['active']) { $active[$address]=$rows; }
        }
        $definitions = array_column($spec->toArray()['fields'],null,'uuid'); $files = []; $byAddress = [];
        foreach ($context['files'] ?? [] as $entry) {
            $address = FieldAddress::fromKey($entry['field_address'] ?? ''); $key = $address->key();
            if (!$instances->contains($address) || !array_key_exists($key,$validated->values) || !in_array($definitions[$address->field]['type'],['file','multiple-files'],true)) { throw new \InvalidArgumentException('Invalid addressed file context.'); }
            $receiptId=$entry['receipt']['uuid'] ?? null;
            if (!is_string($receiptId) || isset($byAddress[$key][$receiptId])) { throw new \InvalidArgumentException('Invalid or duplicate addressed file receipt.'); }
            $entry['field_uuid']=$key; $byAddress[$key][$receiptId]=$entry;
        }
        foreach ($validated->values as $key=>$value) {
            $type=$definitions[FieldAddress::fromKey($key)->field]['type'];
            if (!in_array($type,['file','multiple-files'],true)) { continue; }
            $receipts=$type==='file' ? ($value===null ? [] : [$value]) : $value;
            foreach ($receipts as $receipt) {
                $entry=$byAddress[$key][$receipt['uuid']] ?? null;
                if ($entry===null || !$entry['file'] instanceof StoredFile || $entry['file']->size !== $receipt['size'] || array_intersect_key($entry['receipt'],$receipt) !== $receipt) { throw new \InvalidArgumentException('File fingerprint material does not match its canonical receipt.'); }
                $files[]=$entry; unset($byAddress[$key][$receipt['uuid']]);
            }
            if (($byAddress[$key] ?? []) !== []) { throw new \InvalidArgumentException('Unmatched addressed file receipt.'); }
        }
        return $this->hash($version,$this->files($validated->values,array_replace($context,['files'=>$files])),$context,['instances'=>$active]);
    }

    private function files(array $expected, array $context): array
    {
        $files=[];
        foreach ($context['files'] ?? [] as $entry) {
            $object=$entry['file'];
            if (!$object instanceof StoredFile) { throw new \InvalidArgumentException('Invalid trusted file context.'); }
            $files[$entry['field_uuid']][]=['name'=>$entry['receipt']['name'],'mime'=>$entry['receipt']['mime'],'size'=>$object->size,'checksum'=>$object->checksum];
        }
        foreach ($files as $key=>$receipts) { if (array_key_exists($key,$expected)) { $expected[$key]=$receipts; } }
        return $expected;
    }

    private function hash(int $version, array $values, array $context, ?array $instances = null): string
    {
        $data=[$version,$values,$context['channel'] ?? 'component',$context['locale'] ?? 'en-GB'];
        if ($instances !== null) { $data[]=$instances; }
        return hash_hmac('sha256',CanonicalJson::encode($data),$this->key);
    }
}
