<?php
/**
 * Class AbstractPdoHandler
 */
namespace Moro\Migration\Handler;
use \PDO;
use \Exception;

/**
 * Class AbstractPdoHandler
 * @package Moro\Migration\Handler
 */
abstract class AbstractPdoHandler extends AbstractSqlHandler
{
	/**
	 * @var PDO
	 */
	protected $_connection;

	/**
	 * @param PDO $pdo
	 * @return $this
	 */
	public function setConnection(PDO $pdo)
	{
		$this->_connection = $pdo;
		return $this;
	}

	/**
	 * @return PDO
	 */
	public function getConnection()
	{
		return $this->_connection;
	}

	/**
	 * @param string $table
	 * @param array $columns
	 * @param callable $callback
	 */
	protected function _selectFromTable($table, array $columns, callable $callback)
	{
		$statement = $this->getConnection()->prepare('SELECT '.implode(',', $columns).' FROM '.$table.';');

		foreach ($statement->execute() ? $statement->fetchAll(PDO::FETCH_ASSOC) : [] as $record)
		{
			$callback($record);
		}
	}

	/**
	 * @param callable $callback
	 * @return null|\Exception
	 */
	protected function _transaction(callable $callback)
	{
		$connection = $this->getConnection();

		if ($connection->beginTransaction())
		{
			try
			{
				$callback();
				$connection->commit();
			}
			catch (Exception $exception)
			{
				$connection->rollBack();
				return $exception;
			}
		}
		else
		{
			$callback();
		}

		return true;
	}

	/**
	 * @param string $table
	 * @param array $columns
	 * @param callable $callback
	 */
	protected function _insertRecords($table, array $columns, callable $callback)
	{
		$sqlCols = implode(', ', $columns);
		$sqlVars = implode(', ', array_fill(0, count($columns), '?'));

		$connection = $this->getConnection();
		$statement = $connection->prepare($sql = "INSERT INTO $table ($sqlCols) VALUES ($sqlVars);");

		/** @var \Generator $generator */
		foreach (($generator = $callback()) as $record)
		{
			$statement->execute($record);
			$generator->send($connection->lastInsertId());
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
		$whereCount = count($where);
		$columns = implode(', ',  array_map(function($col) {return $col.' = ?'; } , $columns));
		$where = implode(' AND ', array_map(function($col) {return $col.' = ?'; } , $where));

		$statement = $this->getConnection()->prepare("UPDATE $table SET $columns WHERE $where;");

		/** @var \Generator $generator */
		foreach (($generator = $callback()) as $record)
		{
			$record = array_merge(array_slice($record, $whereCount), array_slice($record, 0, $whereCount));
			$statement->execute($record);
			$generator->send(0);
		}
	}

	/**
	 * @param string $sql
	 */
	protected function _executeSql($sql)
	{
		$this->getConnection()->exec($sql);
	}

	/**
	 * @param string $table
	 * @param string $primaryKey
	 * @param mixed $value
	 * @return array|null
	 */
	protected function _selectFromTableByPK($table, $primaryKey, $value)
	{
		$statement = $this->getConnection()->prepare("SELECT * FROM $table WHERE $primaryKey = ?;");
		return $statement->execute([$value]) ? $statement->fetch(PDO::FETCH_ASSOC) : null;
	}

	/**
	 * @param string $table
	 * @param string $primaryKey
	 * @param mixed $value
	 */
	protected function _deleteFromTableByPK($table, $primaryKey, $value)
	{
		$statement = $this->getConnection()->prepare("DELETE FROM $table WHERE $primaryKey = ?;");
		$statement->execute([$value]);
	}
}