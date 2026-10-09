<?php
declare(strict_types=1);

test('file fields accept only server supplied receipts and reject visitor file references', function (): void {
    $draft = definition(); $uuid = $draft['fields'][0]['uuid'];
    $draft['fields'][0]['type'] = 'file'; $draft['fields'][0]['config'] = ['required' => true, 'extensions' => ['txt'], 'mime_types' => ['text/plain'], 'max_bytes' => 1024];
    $result = compiler()->compile($draft); same(true, $result->successful());
    $receipt = ['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'name' => 'test.txt', 'mime' => 'text/plain', 'size' => 123, 'storage_key' => 'must-not-persist'];
    same(['required'], validation()->validate($result->spec, [$uuid => $receipt])->errors[$uuid]);
    $accepted = validation()->validate($result->spec, [], [$uuid => $receipt]); same(true, $accepted->valid()); same(false, isset($accepted->values[$uuid]['storage_key']));
    $receipt['size'] = 1025; same(['file_size'], validation()->validate($result->spec, [], [$uuid => $receipt])->errors[$uuid]);
    $draft['fields'][0]['config']['extensions'] = ['php']; same(false, compiler()->compile($draft)->successful());
});
