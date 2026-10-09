<?php
declare(strict_types=1);

test('request metadata requires strict independent opt-ins and bounded valid transport values', function (): void {
    $select = Nicode\FormStudio\Privacy\RequestMetadata::select(...);
    $raw = ['ip' => '2001:0db8:0:0:0:0:0:1', 'user_agent' => "Browser\r\n<script>😀</script>"];
    same([], $select([], 'full', $raw));
    same([], $select(['store_ip' => 'true', 'store_user_agent' => 1], 'full', $raw));
    same(['ip' => '2001:db8::1'], $select(['store_ip' => true], 'metadata', $raw));
    same(['user_agent' => 'Browser<script>😀</script>'], $select(['store_user_agent' => true], 'full', $raw));
    same([], $select(['store_ip' => true, 'store_user_agent' => true], 'none', $raw));
    foreach ([['ip' => 'unknown', 'user_agent' => "\xff"], ['ip' => ['127.0.0.1'], 'user_agent' => ['browser']]] as $invalid) {
        same([], $select(['store_ip' => true, 'store_user_agent' => true], 'full', $invalid));
    }
    $bounded = $select(['store_user_agent' => true], 'full', ['user_agent' => str_repeat('😀', 200)]);
    same(512, strlen($bounded['user_agent'])); same(true, mb_check_encoding($bounded['user_agent'], 'UTF-8'));
});

test('request context does not collect metadata until opted in and keeps it out of rules', function (): void {
    $calls = 0;
    $context = new Nicode\FormStudio\Submission\RequestContext(0, [1], 'en-GB', 'session', str_repeat('a', 64), true, metadataProvider: static function () use (&$calls): array { $calls++; return ['ip' => '127.0.0.1', 'user_agent' => 'Browser']; });
    same([], $context->requestMetadata([], 'full'));
    same([], $context->requestMetadata(['store_ip' => true], 'none'));
    same([], $context->requestMetadata(['store_ip' => 1], 'full'));
    same(0, $calls);
    same(['ip' => '127.0.0.1'], $context->requestMetadata(['store_ip' => true], 'full'));
    same(1, $calls); same(false, array_key_exists('request_metadata', $context->ruleContext()));
});

test('publication rejects null and coerced privacy flags', function (): void {
    foreach (['store_user', 'store_ip', 'store_user_agent'] as $key) {
        foreach ([null, 0, 1, 'true', [], ''] as $invalid) {
            $errors = (new Nicode\FormStudio\Compiler\RuntimePolicyValidator())->validate(['privacy' => [$key => $invalid]]);
            same(1, count($errors));
        }
    }
});
