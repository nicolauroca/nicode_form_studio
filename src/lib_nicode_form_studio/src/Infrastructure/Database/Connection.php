<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Infrastructure\Database;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/** Internal repository adapter. SQL text is authored by repositories, never users. */
final readonly class Connection
{
    private object $transactions;
    public function __construct(private DatabaseInterface $database) { $this->transactions = (object) ['depth' => 0, 'failed' => false]; }
    public function inTransaction(): bool { return $this->transactions->depth > 0; }
    public function isPostgresql(): bool { return in_array($this->database->getName(), ['pgsql', 'postgresql'], true); }

    public function rows(string $sql, array $parameters = []): array
    {
        $this->prepare($sql, $parameters);
        return $this->database->loadAssocList();
    }
    public function row(string $sql, array $parameters = []): ?array
    {
        $this->prepare($sql, $parameters);
        return $this->database->loadAssoc() ?: null;
    }
    public function execute(string $sql, array $parameters = []): int
    {
        $this->prepare($sql, $parameters);
        $this->database->execute();
        return $this->database->getAffectedRows();
    }
    public function insert(string $table, array $values): int
    {
        $columns = array_keys($values);
        $names = array_map($this->quote(...), $columns);
        $parameters = [];
        foreach (array_values($values) as $i => $value) { $parameters[':p' . $i] = $value; }
        $sql = 'INSERT INTO ' . $this->table($table) . ' (' . implode(', ', $names) . ') VALUES (' . implode(', ', array_keys($parameters)) . ')';
        if (in_array($this->database->getName(), ['pgsql', 'postgresql'], true)) {
            return (int) $this->row($sql . ' RETURNING ' . $this->quote('id'), $parameters)['id'];
        }
        $this->execute($sql, $parameters);
        return (int) $this->database->insertid();
    }
    public function transaction(callable $operation): mixed
    {
        if ($this->transactions->failed) { throw new \RuntimeException('Database transaction context was lost.'); }
        $this->database->transactionStart(true);
        $this->transactions->depth++;
        try { $result = $operation(); $this->database->transactionCommit(true); return $result; }
        catch (\Throwable $originalFailure) {
            try { $this->database->transactionRollback(true); }
            catch (\Throwable) {
                $this->transactions->failed = true;
                throw new \RuntimeException('Database transaction rollback failed; this connection cannot be reused.', 0, $originalFailure);
            }
            throw $originalFailure;
        }
        finally { $this->transactions->depth--; }
    }
    /** Bounded multi-row insert to avoid a query for every indexed field. */
    public function insertMany(string $table, array $rows, int $chunkSize = 200): void
    {
        if ($rows === []) { return; }
        if ($chunkSize < 1 || $chunkSize > 500) { throw new \InvalidArgumentException('Invalid insert chunk size.'); }
        $columns = array_keys($rows[0]);
        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $groups = []; $parameters = [];
            foreach ($chunk as $i => $row) {
                if (array_keys($row) !== $columns) { throw new \InvalidArgumentException('Batch insert columns differ.'); }
                $placeholders = [];
                foreach (array_values($row) as $j => $value) { $key = ':r' . $i . 'c' . $j; $placeholders[] = $key; $parameters[$key] = $value; }
                $groups[] = '(' . implode(', ', $placeholders) . ')';
            }
            $this->execute('INSERT INTO ' . $this->table($table) . ' (' . implode(', ', array_map($this->quote(...), $columns)) . ') VALUES ' . implode(', ', $groups), $parameters);
        }
    }
    public function table(string $table): string
    {
        return $this->quote('#__nicode_form_studio_' . $table);
    }
    /** Shared row locks permit concurrent writers while fencing rare package purge. */
    public function sharedLock(): string
    {
        return in_array($this->database->getName(), ['pgsql', 'postgresql'], true) ? ' FOR SHARE' : ' LOCK IN SHARE MODE';
    }
    public function quote(string $name): string
    {
        if (preg_match('/^(?:#__)?[a-z][a-z0-9_]*$/D', $name) !== 1) { throw new \InvalidArgumentException('Invalid internal SQL identifier.'); }
        return $this->database->quoteName($name);
    }
    private function prepare(string $sql, array $parameters): void
    {
        if ($this->transactions->failed) { throw new \RuntimeException('Database transaction context was lost.'); }
        $query = $this->database->createQuery()->setQuery($sql);
        foreach ($parameters as $name => &$value) {
            $type = match (true) { $value === null => ParameterType::NULL, is_int($value) => ParameterType::INTEGER, is_bool($value) => ParameterType::BOOLEAN, default => ParameterType::STRING };
            $query->bind($name, $value, $type);
        }
        unset($value);
        $this->database->connect();
        $native = $this->database->getConnection();
        if ($native instanceof \PDO) {
            // Scope the exception adapter to this prepared statement. Restore
            // the previous class before execution or any unrelated Joomla query.
            $previous = $native->getAttribute(\PDO::ATTR_STATEMENT_CLASS);
            $native->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [StrictPdoStatement::class]);
            try { $this->database->setQuery($query); }
            finally { $native->setAttribute(\PDO::ATTR_STATEMENT_CLASS, $previous); }
        } else { $this->database->setQuery($query); }
    }
}
