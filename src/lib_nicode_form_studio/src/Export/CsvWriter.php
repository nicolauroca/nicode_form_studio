<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Export;

use Nicode\FormStudio\Domain\CanonicalJson;

final class CsvWriter
{
    public function cell(mixed $value): string
    {
        $text = match (true) { $value === null => '', is_bool($value) => $value ? 'true' : 'false', is_array($value) => CanonicalJson::encode($value), is_scalar($value) => (string) $value, default => throw new \InvalidArgumentException('CSV values must be canonical data.') };
        if (!mb_check_encoding($text, 'UTF-8')) { throw new \InvalidArgumentException('CSV requires UTF-8.'); }
        // Spreadsheet programs can interpret formula prefixes after whitespace.
        return preg_match('/^[=+\-@\x00-\x20\x{FEFF}]/u', $text) === 1 ? "'" . $text : $text;
    }
    /** @param resource $stream */
    public function row($stream, array $values): void
    {
        if (fputcsv($stream, array_map($this->cell(...), $values), ',', '"', '', "\r\n") === false) { throw new \RuntimeException('Unable to write CSV.'); }
    }
}
