<?php
declare(strict_types=1);

use Joomla\CMS\Cache\Cache;
use Nicode\FormStudio\Infrastructure\Joomla\SourceCache;

test('Joomla source cache shares JSON entries with exact expiry and safe outage fallback', function (): void {
    $backend = new class extends Cache {
        public array $entries = [];
        public bool $fail = false;
        public function __construct() {}
        public function get($id, $group = null) { if ($this->fail) { throw new RuntimeException('cache outage'); } return $this->entries[$id] ?? false; }
        public function store($data, $id, $group = null) { if ($this->fail) { throw new RuntimeException('cache outage'); } $this->entries[$id] = $data; return true; }
        public function remove($id, $group = null) { unset($this->entries[$id]); return true; }
    };
    $now = 100; $clock = static function () use (&$now): int { return $now; };
    $first = new SourceCache($backend, $clock); $second = new SourceCache($backend, $clock);
    $first->set('choices', [['value' => 'ES', 'label' => 'España']], 3);
    same([['label' => 'España', 'value' => 'ES']], $second->get('choices'));
    $now = 103; same(null, $second->get('choices')); same(null, $first->get('choices'));
    $backend->entries['corrupt'] = 'invalid JSON'; same(null, $first->get('corrupt'));
    $backend->fail = true; $first->set('local', [], 2); same([], $first->get('local')); same(null, $second->get('missing'));
    $first->delete('local'); same(null, $first->get('local'));
    raises(InvalidArgumentException::class, fn () => $first->set('invalid', [], 86401));
});

test('source cache diagnostics distinguish disabled and failed backends without writes or disclosure', function (): void {
    $backend = new class extends Cache {
        public bool $enabled = false; public bool $fail = false; public int $reads = 0; public int $writes = 0;
        public function __construct() {}
        public function getCaching() { return $this->enabled; }
        public function get($id, $group = null) { $this->reads++; if ($this->fail) { throw new RuntimeException('secret host and credential'); } return false; }
        public function store($data, $id, $group = null) { $this->writes++; return true; }
        public function remove($id, $group = null) { $this->writes++; return true; }
    };
    $cache = new SourceCache($backend);
    same(['status' => 'not_configured', 'reason' => 'cache_disabled'], $cache->diagnostics()); same(0, $backend->reads);
    $backend->enabled = true;
    same(['status' => 'ok', 'reason' => 'cache_read_unverified'], $cache->diagnostics()); same(1, $backend->reads);
    $backend->fail = true;
    same(['status' => 'unavailable', 'reason' => 'cache_backend_unavailable'], $cache->diagnostics()); same(2, $backend->reads);
    same(0, $backend->writes);
});
