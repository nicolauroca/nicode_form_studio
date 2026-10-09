<?php
declare(strict_types=1);

use Nicode\FormStudio\Registry\RuleOperatorRegistry;
use Nicode\FormStudio\Validation\SafePattern;

foreach (json_decode(file_get_contents(__DIR__ . '/../fixtures/operators.json'), true, 512, JSON_THROW_ON_ERROR) as $index => $case) {
    test('shared operator ' . $index . ': ' . $case['operator'], static function () use ($case): void {
        same($case['expected'], RuleOperatorRegistry::core()->get($case['operator'])->evaluate($case['left'], $case['right'], $case['datatype']));
    });
}

foreach (json_decode(file_get_contents(__DIR__ . '/../fixtures/patterns.json'), true, 512, JSON_THROW_ON_ERROR) as $index => $case) {
    test('shared pattern ' . $index, static function () use ($case): void {
        same($case['valid'], SafePattern::valid($case['pattern']));
        if ($case['valid']) { same($case['matches'], SafePattern::matches($case['pattern'], $case['value'])); }
    });
}
