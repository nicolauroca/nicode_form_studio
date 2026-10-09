<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Infrastructure\Database;

use Nicode\FormStudio\Contract\RateLimiterInterface;
use Nicode\FormStudio\Security\RateLimitResult;

final readonly class RateLimiter implements RateLimiterInterface
{
    public function __construct(private Connection $db, private ?\Closure $clock = null) {}
    public function consume(string $scopeHash, int $limit, int $windowSeconds): RateLimitResult
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $scopeHash) !== 1 || $limit < 1 || $limit > 1000000 || $windowSeconds < 1 || $windowSeconds > 86400) { throw new \InvalidArgumentException('Invalid rate limit policy.'); }
        $now = $this->clock === null ? time() : ($this->clock)(); $window = intdiv($now, $windowSeconds) * $windowSeconds;
        $start = gmdate('Y-m-d H:i:s', $window); $expires = gmdate('Y-m-d H:i:s', $window + $windowSeconds);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return $this->db->transaction(function () use ($scopeHash, $limit, $windowSeconds, $window, $now, $start, $expires): RateLimitResult {
                    // Establish and lock the unique window without raising an
                    // integrity error (which aborts PostgreSQL transactions).
                    $conflict = $this->db->isPostgresql() ? ' ON CONFLICT (scope_hash, window_start) DO NOTHING' : ' ON DUPLICATE KEY UPDATE attempts = attempts';
                    $this->db->execute('INSERT INTO ' . $this->db->table('rate_limits') . ' (scope_hash, window_start, expires_at, attempts) VALUES (:scope, :start, :expires, 0)' . $conflict, [':scope' => $scopeHash, ':start' => $start, ':expires' => $expires]);
                    $row = $this->db->row('SELECT attempts FROM ' . $this->db->table('rate_limits') . ' WHERE scope_hash = :scope AND window_start = :start FOR UPDATE', [':scope' => $scopeHash, ':start' => $start]);
                    if ($row === null) { throw new \RuntimeException('Rate window unavailable.'); }
                    $allowed = (int) $row['attempts'] < $limit;
                    if ($allowed) { $this->db->execute('UPDATE ' . $this->db->table('rate_limits') . ' SET attempts = attempts + 1 WHERE scope_hash = :scope AND window_start = :start', [':scope' => $scopeHash, ':start' => $start]); }
                    return new RateLimitResult($allowed, $allowed ? 0 : $window + $windowSeconds - $now);
                });
            } catch (\Joomla\Database\Exception\ExecutionFailureException $error) {
                // Concurrent first writers can collide on the unique window key.
                if ($attempt === 2) { throw $error; }
            }
        }
        throw new \LogicException('Rate limit loop exhausted.');
    }
}
