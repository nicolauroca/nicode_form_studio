<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Privacy;
final readonly class RetentionPolicy
{
    public function __construct(public string $action = 'indefinite', public int $amount = 0, public string $unit = 'days')
    {
        if (!in_array($action, ['indefinite', 'delete', 'anonymize'], true) || !in_array($unit, ['days', 'months', 'years'], true) || ($action !== 'indefinite' && ($amount < 1 || $amount > 9999))) { throw new \InvalidArgumentException('Invalid retention policy.'); }
    }
    public function expiresAt(int $receivedAt): ?string
    {
        if ($this->action === 'indefinite') { return null; }
        $start = (new \DateTimeImmutable('@' . $receivedAt))->setTimezone(new \DateTimeZone('UTC'));
        // Calendar months/years clamp to the last day of the destination month.
        if ($this->unit === 'days') { $end = $start->add(new \DateInterval('P' . $this->amount . 'D')); }
        else {
            $months = $this->amount * ($this->unit === 'years' ? 12 : 1);
            $first = $start->modify('first day of this month')->add(new \DateInterval('P' . $months . 'M'));
            $end = $first->setDate((int) $first->format('Y'), (int) $first->format('m'), min((int) $start->format('d'), (int) $first->format('t')));
        }
        if ((int) $end->format('Y') > 9999) { throw new \InvalidArgumentException('Retention exceeds database calendar range.'); }
        return $end->format('Y-m-d H:i:s');
    }
}
