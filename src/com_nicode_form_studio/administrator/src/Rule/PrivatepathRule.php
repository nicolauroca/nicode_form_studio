<?php
declare(strict_types=1);
namespace Nicode\Component\FormStudio\Administrator\Rule;
defined('_JEXEC') or die;
final class PrivatepathRule extends \Joomla\CMS\Form\FormRule
{
    public function test(\SimpleXMLElement $element, $value, $group = null, ?\Joomla\Registry\Registry $input = null, ?\Joomla\CMS\Form\Form $form = null)
    {
        if ($value === '' || $value === null) { return true; }
        if (!is_string($value) || str_contains($value, "\0") || !preg_match('~^(?:[A-Za-z]:[\\\\/]|/|\\\\\\\\)~', $value)) { return false; }
        $path = realpath($value); $public = realpath(JPATH_ROOT);
        if ($path === false || $public === false || !is_dir($path) || !is_writable($path)) { return false; }
        $normalize = static fn (string $p): string => (PHP_OS_FAMILY === 'Windows' ? strtolower(str_replace('\\', '/', $p)) : $p) . '/';
        return !str_starts_with($normalize($path), $normalize($public));
    }
}
