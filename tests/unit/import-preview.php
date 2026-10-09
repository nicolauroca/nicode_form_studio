<?php
declare(strict_types=1);
use Nicode\FormStudio\Domain\CanonicalJson;
use Nicode\FormStudio\Transfer\{DefinitionPackage, ImportPreview, ImportReviewToken};

test('import preview distinguishes duplicate, update and UUID conflicts without modifying its input', function (): void {
    $packages = new DefinitionPackage(['fields' => registry()]); $preview = new ImportPreview($packages, compiler());
    $draft = definition(); $original = $draft; $json = CanonicalJson::encode($packages->export($draft));
    $duplicate = $preview->analyze($json, 'duplicate', $draft);
    same(true, $duplicate['can_import_draft']); same(true, $duplicate['identity_remap']); same(1, $duplicate['counts']['fields']); same(null, $duplicate['comparison']);
    $conflict = $preview->analyze($json, 'conflict', $draft); same(false, $conflict['can_import_draft']); same('import.uuid_collision', $conflict['conflicts'][0]['code']);
    $missing = $preview->analyze($json, 'update'); same(false, $missing['can_import_draft']); same('import.target_missing', $missing['conflicts'][0]['code']);
    $changed = $draft; $changed['name'] = 'Before import';
    $update = $preview->analyze($json, 'update', $changed); same(true, $update['can_import_draft']); same(false, $update['identity_remap']); same(true, is_array($update['comparison']));
    same($original, $draft);
    $changed['uuid'] = Nicode\FormStudio\Domain\Uuid::create(); raises(InvalidArgumentException::class, fn () => $preview->analyze($json, 'update', $changed));
    raises(InvalidArgumentException::class, fn () => $preview->analyze($json, 'overwrite'));
});

test('import previews retain semantic diagnostics for repairable drafts', function (): void {
    $packages = new DefinitionPackage(['fields' => registry()]); $draft = definition(); $draft['fields'][0]['type'] = 'fixture.missing';
    $result = (new ImportPreview($packages, compiler()))->analyze(CanonicalJson::encode($packages->export($draft)), 'duplicate');
    same(true, $result['can_import_draft']); same(false, $result['definition_valid']); same(true, count($result['diagnostics']) > 0);
});

test('import review signatures bind actor payload choices and revision and expire at the exact boundary', function (): void {
    $now = 100; $tokens = new ImportReviewToken(str_repeat('k', 32), static function () use (&$now): int { return $now; });
    $review = ['payload_hash' => str_repeat('a', 64), 'policy' => 'update', 'target_revision' => 4, 'name' => 'Imported', 'alias' => 'imported'];
    $token = $tokens->issue(7, $review); $tokens->verify($token, 7, array_reverse($review, true));
    raises(DomainException::class, fn () => $tokens->verify($token, 8, $review));
    foreach ($review as $key => $value) {
        $changed = $review; $changed[$key] = is_int($value) ? $value + 1 : $value . '-changed';
        raises(DomainException::class, fn () => $tokens->verify($token, 7, $changed));
    }
    $now = 999; $tokens->verify($token, 7, $review); $now = 1000;
    raises(DomainException::class, fn () => $tokens->verify($token, 7, $review));
    raises(DomainException::class, fn () => $tokens->verify('invalid', 7, $review));
    raises(InvalidArgumentException::class, fn () => new ImportReviewToken('short', static fn (): int => 1));
});
