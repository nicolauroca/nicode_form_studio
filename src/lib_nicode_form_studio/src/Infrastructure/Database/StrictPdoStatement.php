<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Infrastructure\Database;

/** Preserve native Joomla bindings/fetching without implicit failed-query replay. */
final class StrictPdoStatement extends \PDOStatement
{
    protected function __construct() {}
    public function execute(?array $params = null): bool
    {
        try { return parent::execute($params); }
        catch (\PDOException $error) {
            // Joomla's PDOException recovery can reconnect after PostgreSQL
            // aborts a transaction. Let our repository roll back it instead.
            throw new \Joomla\Database\Exception\ExecutionFailureException($this->queryString, 'Database statement failed.', $error->getCode(), $error);
        }
    }
}
