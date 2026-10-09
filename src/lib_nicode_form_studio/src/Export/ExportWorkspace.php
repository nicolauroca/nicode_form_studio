<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Export;

use Nicode\FormStudio\Domain\Uuid;

/** Local private artifacts. Download authority comes from the completed job. */
final readonly class ExportWorkspace
{
    private string $root;
    public function __construct(string $root, string $publicRoot)
    {
        if ($root === '' || $publicRoot === '') { throw new \InvalidArgumentException('Export roots must be explicit.'); }
        $private = realpath($root); $public = realpath($publicRoot);
        if ($private === false || $public === false || !is_dir($private) || !is_writable($private)) { throw new \InvalidArgumentException('Export roots must exist.'); }
        $normalize = static fn (string $path): string => strtolower(str_replace('\\', '/', $path)) . '/';
        if (str_starts_with($normalize($private), $normalize($public))) { throw new \InvalidArgumentException('Exports must be outside the public root.'); }
        $this->root = $private;
    }
    /** Retry truncates uncheckpointed bytes while holding the same artifact lock. */
    public function append(string $jobUuid, int $checkpointBytes, array $header, array $rows, \Closure $assertLease): int
    {
        return $this->write($jobUuid, $checkpointBytes, 'csv', $assertLease, static function ($stream) use ($checkpointBytes, $header, $rows): void {
            $csv = new CsvWriter();
            if ($checkpointBytes === 0) { $csv->row($stream, $header); }
            foreach ($rows as $row) { $csv->row($stream, $row); }
        });
    }
    /** Build one JSON array incrementally; only a completed job may expose it. */
    public function appendJson(string $jobUuid, int $checkpointBytes, array $records, bool $complete, \Closure $assertLease): int
    {
        return $this->write($jobUuid, $checkpointBytes, 'json', $assertLease, static function ($stream) use ($checkpointBytes, $records, $complete): void {
            $write = static function (string $bytes) use ($stream): void {
                $length = strlen($bytes); $offset = 0;
                while ($offset < $length) {
                    $written = fwrite($stream, substr($bytes, $offset));
                    if ($written === false || $written === 0) { throw new \RuntimeException('Unable to write JSON export.'); }
                    $offset += $written;
                }
            };
            if ($checkpointBytes === 0) { $write('['); }
            $hasRecords = $checkpointBytes > 1;
            foreach ($records as $record) {
                if (!is_array($record) || array_is_list($record)) { throw new \InvalidArgumentException('JSON export records must be keyed canonical data.'); }
                $encoded = \Nicode\FormStudio\Domain\CanonicalJson::encode($record);
                $write(($hasRecords ? ',' : '') . $encoded); $hasRecords = true;
            }
            if ($complete) { $write(']'); }
        });
    }
    private function write(string $jobUuid, int $checkpointBytes, string $format, \Closure $assertLease, \Closure $write): int
    {
        if ($checkpointBytes < 0) { throw new \InvalidArgumentException('Invalid export offset.'); }
        $path = $this->path($jobUuid, $format); $stream = fopen($path, 'c+b');
        if ($stream === false) { throw new \RuntimeException('Export workspace unavailable.'); }
        try {
            if (!flock($stream, LOCK_EX)) { throw new \RuntimeException('Export lock unavailable.'); }
            $assertLease();
            $size = fstat($stream)['size'];
            if ($size < $checkpointBytes) { throw new \RuntimeException('Checkpointed export bytes are missing.'); }
            if (!ftruncate($stream, $checkpointBytes) || fseek($stream, $checkpointBytes) !== 0) { throw new \RuntimeException('Unable to restore export checkpoint.'); }
            $write($stream);
            if (!fflush($stream) || !fsync($stream)) { throw new \RuntimeException('Unable to persist export bytes.'); }
            $offset = ftell($stream);
            if ($offset === false) { throw new \RuntimeException('Export position unavailable.'); }
            return $offset;
        } finally { flock($stream, LOCK_UN); fclose($stream); }
    }
    public function open(string $jobUuid, string $format = 'csv')
    {
        $stream = fopen($this->path($jobUuid, $format), 'rb');
        if ($stream === false) { throw new \OutOfBoundsException('Export unavailable.'); }
        return $stream;
    }
    public function delete(string $jobUuid, string $format = 'csv'): void
    {
        $path = $this->path($jobUuid, $format);
        if (is_file($path) && !@unlink($path)) { throw new \Nicode\FormStudio\Jobs\RetryableJobFailure('export_cleanup_busy'); }
    }
    private function path(string $uuid, string $format): string
    {
        if (!Uuid::valid($uuid)) { throw new \InvalidArgumentException('Invalid export identity.'); }
        if (!in_array($format, ['csv', 'json'], true)) { throw new \InvalidArgumentException('Invalid export format.'); }
        $path = $this->root . DIRECTORY_SEPARATOR . $uuid . '.' . $format;
        if (is_link($path)) { throw new \DomainException('Linked export artifact rejected.'); }
        return $path;
    }
}
