<?php
declare(strict_types=1);
test('text lengths count Unicode code points without native UTF16 truncation', function (): void {
    foreach (json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/text-length.json'), true, flags: JSON_THROW_ON_ERROR) as $case) {
        foreach (['text', 'textarea', 'password', 'search', 'telephone'] as $type) { same($case['errors'], registry()->get($type)->validate($case['value'], $case['config'])); }
    }
    foreach (['text', 'textarea', 'password', 'search', 'telephone'] as $type) {
        $html = (new Nicode\FormStudio\Rendering\CoreFieldRenderer())->render(['uuid' => 'unicode', 'name' => 'unicode', 'type' => $type, 'config' => ['min_length' => 1, 'max_length' => 1]], 'fixture', '😀');
        same(false, str_contains($html, 'maxlength=')); same(false, str_contains($html, 'minlength='));
    }
});
test('shared email, color and URL policies preserve valid values and reject unsupported input', function (): void {
    foreach (json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/text-types.json'), true, flags: JSON_THROW_ON_ERROR) as $case) {
        same($case['errors'], registry()->get($case['type'])->validate($case['value'], []));
    }
});
test('unquoted email length bounds agree with browser policy', function (): void {
    foreach ([
        [str_repeat('a', 64) . '@example.test', []], [str_repeat('a', 65) . '@example.test', ['email']],
        ['a@' . str_repeat('b', 63) . '.test', []], ['a@' . str_repeat('b', 64) . '.test', ['email']],
        [str_repeat('a', 64) . '@' . implode('.', [str_repeat('b', 63), str_repeat('c', 63), str_repeat('d', 61)]), []],
        [str_repeat('a', 64) . '@' . implode('.', [str_repeat('b', 63), str_repeat('c', 63), str_repeat('d', 62)]), ['email']],
    ] as [$value, $errors]) { same($errors, registry()->get('email')->validate($value, [])); }
});
