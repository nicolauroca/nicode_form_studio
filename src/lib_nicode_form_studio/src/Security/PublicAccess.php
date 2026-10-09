<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Security;

final class PublicAccess
{
    public function __construct(private readonly ?\Closure $maintenance = null) {}
    /** Levels, locale and time must originate from Joomla application context. */
    public function assert(array $form, array $viewLevels, string $language, int $now): void
    {
        $date = gmdate('Y-m-d H:i:s', $now);
        if (($this->maintenance !== null && ($this->maintenance)()) || ($form['state'] ?? '') !== 'published' || empty($form['published_version_id'])
            || !in_array((int) ($form['access'] ?? 0), $viewLevels, true)
            || !in_array($form['language'] ?? '', ['*', $language], true)
            || (!empty($form['publish_up']) && $form['publish_up'] > $date)
            || (!empty($form['publish_down']) && $form['publish_down'] <= $date)) {
            throw new \OutOfBoundsException('Form not available.');
        }
    }
}
