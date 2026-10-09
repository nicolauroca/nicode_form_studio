<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Transfer;

use Nicode\FormStudio\Domain\CanonicalJson;

/** Bind a short-lived preview approval to its actor, payload, choices and target revision. */
final readonly class ImportReviewToken
{
    public function __construct(private string $key, private \Closure $clock)
    {
        if (strlen($key) < 32) { throw new \InvalidArgumentException('Import review signing key is too short.'); }
    }
    public function issue(int $actor, array $review): string
    {
        if ($actor < 1) { throw new \InvalidArgumentException('Invalid review actor.'); }
        $expires = ($this->clock)() + 900;
        return $expires . '.' . $this->signature($actor, $expires, $review);
    }
    public function verify(string $token, int $actor, array $review): void
    {
        if (preg_match('/^([0-9]{1,12})\.([a-f0-9]{64})$/D', $token, $parts) !== 1) { throw new \DomainException('Invalid import review.'); }
        $expires = (int) $parts[1]; $now = ($this->clock)();
        if ($actor < 1 || $expires <= $now || $expires > $now + 900 || !hash_equals($this->signature($actor, $expires, $review), $parts[2])) { throw new \DomainException('Import review expired or changed.'); }
    }
    private function signature(int $actor, int $expires, array $review): string
    {
        return hash_hmac('sha256', CanonicalJson::encode(['purpose' => 'definition-import-review-v1', 'actor' => $actor, 'expires' => $expires, 'review' => $review]), $this->key);
    }
}
