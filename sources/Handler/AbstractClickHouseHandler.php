<?php
/**
 * Class AbstractClickHouseHandler
 */
namespace Moro\Migration\Handler;

/**
 * Class AbstractClickHouseHandler
 * @package Moro\Migration\Handler
 */
abstract class AbstractClickHouseHandler extends AbstractSqlHandler
{
    /**
     * @param string $name
     * @return bool
     */
    protected function _isTableExists($name)
    {
        return $this->_callQuery(sprintf('EXISTS TABLE %s', $name))[0][0] == 1;
    }

    /**
     * @param string $table
     * @param array $columns
     * @param callable $callback
     */
    protected function _selectFromTable($table, array $columns, callable $callback)
    {
        $sql = sprintf('SELECT %s FROM %s', implode(', ', $columns), $table);

        foreach ($this->_callQuery($sql) as $record) {
            $callback($record);
        }
    }

    /**
     * @param callable $callback
     * @return bool|\Exception
     */
    protected function _transaction(callable $callback)
    {
        $callback();

        return true;
    }

    /**
     * @param string $table
     * @param array $columns
     * @param callable $callback
     */
    protected function _insertRecords($table, array $columns, callable $callback)
    {
        $values = array_combine(array_map(function ($value) {
            return preg_replace(self::PCRE_COLUMN_NAME, '$2', $value);
        }, $columns), array_map(function ($value) {
                return preg_replace(self::PCRE_COLUMN_VALUE, '$1?$3', $value);
            }, $columns));

        $sql = sprintf('INSERT INTO %s (%s) VALUES ', $table, implode(', ', array_keys($values)));
        $sql .= '(' . implode(', ', array_values($values)) . ')';

        /** @var \Generator $generator */
        foreach (($generator = $callback()) as $record) {
            try {
                $this->_callUpdate($sql, $record);
                $generator->send(true);
            } /** @noinspection PhpRedundantCatchClauseInspection */ catch (\Exception $exception) {
                $this->warning('Skip record: ' . implode(', ', $record));
                $generator->send(0);
            }
        }
    }

    /**
     * @param string $table
     * @param array $columns
     * @param callable $callback
     * @param array $where
     */
    protected function _updateRecords($table, array $columns, callable $callback, array $where)
    {
        $sql = sprintf('ALTER TABLE %s UPDATE', $table);

        foreach (array_values($columns) as $index => $value) {
            $column = preg_replace(self::PCRE_COLUMN_NAME, '$2', $value);
            $value = preg_replace(self::PCRE_COLUMN_VALUE, '$1?$3', $value);
            $sql .= ($index ? ', ' : ' ') . $column . ' = ' . $value;
        }

        $sql .= ' WHERE ';

        foreach (array_values($where) as $index => $col) {
            $sql.= ($index ? ' AND ' : ' ').$col.' = ?';
        }

        /** @var \Generator $generator */
        foreach (($generator = $callback()) as $record) {
            $record = array_merge(array_slice($record, count($where)), array_slice($record, 0, count($where)));
            $rowCount = $this->_callUpdate($sql, $record);
            $generator->send($rowCount);
        }
    }

    /**
     * @param string $table
     * @param null $reserved
     * @param callable $callback
     * @param array $where
     */
    protected function _deleteRecords($table, $reserved, callable $callback, array $where)
    {
        unset($reserved);
        $sql = sprintf('ALTER TABLE %s DELETE WHERE', $table);

        foreach (array_values($where) as $index => $col) {
            $sql.= ($index ? ' AND ' : ' ').$col.' = ?';
        }

        /** @var \Generator $generator */
        foreach (($generator = $callback()) as $record)
        {
            $rowCount = $this->_callUpdate($sql, $record);
            $generator->send($rowCount);
        }
    }

    /**
     * @param string $sql
     */
    protected function _executeSql($sql)
    {
        if (strpos($sql, 'SELECT') !== false) {
            $this->_callQuery($sql);
        } else {
            $this->_callUpdate($sql);
        }
    }

    /**
     * @param string $table
     * @param string $primaryKey
     * @param mixed $value
     * @return array|null
     */
    protected function _selectFromTableByPK($table, $primaryKey, $value)
    {
        $sql = sprintf('SELECT * FROM %s WHERE %s = ?', $table, $primaryKey);

        return $this->_callQuery($sql, [$value]) ?: null;
    }

    /**
     * @param string $table
     * @param string $primaryKey
     * @param mixed $value
     */
    protected function _deleteFromTableByPK($table, $primaryKey, $value)
    {
        $sql = sprintf('ALTER TABLE %s DELETE WHERE %s = ?', $table, $primaryKey);

        $this->_callUpdate($sql, [$value]);
    }

    /**
     * @param string $sql
     * @param array|null $params
     * @return array
     */
    abstract protected function _callQuery(string $sql, array $params = null): array;

    /**
     * @param string $sql
     * @param array|null $params
     * @return int
     */
    abstract protected function _callUpdate(string $sql, array $params = null): int;
}