<?php
/**
 * Class PdoMySQLHandler
 */
namespace Moro\Migration\Handler;
use \PDO;
use \Exception;

/**
 * Class PdoMySQLHandler
 * @package Moro\Migration\Handler
 */
class PdoMySQLHandler extends AbstractSqlHandler
{
	/**
	 * @var string  The name of service for save migration information.
	 */
	protected $_serviceName = 'team.migrations.pdo-mysql';

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
	 * @param string $name
	 * @return bool
	 */
	protected function _isTableExists($name)
	{
		$statement = $this->getConnection()->prepare('show tables;');

		foreach ($statement->execute() ? $statement->fetchAll(PDO::FETCH_COLUMN, 0) : [] as $tableName)
		{
			if ($tableName == $name)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $table
	 * @param array $columns
	 * @param callable $callback
	 */
	protected function _selectFromTable($table, array $columns, callable $callback)
	{
		$statement = $this->getConnection()->prepare('SELECT `'.implode('`,`', $columns).'` FROM `'.$table.'`;');

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
		$sqlCols = implode('`,`', $columns);
		$sqlVars = implode(', ', array_fill(0, count($columns), '?'));

		$connection = $this->getConnection();
		$statement = $connection->prepare($sql = "INSERT INTO `$table` (`$sqlCols`) VALUES ($sqlVars);");

		/** @var \Generator $generator */
		foreach (($generator = $callback()) as $record)
		{
			$statement->execute($record);
			$generator->send($connection->lastInsertId());
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
		$statement = $this->getConnection()->prepare("SELECT * FROM `$table` WHERE `$primaryKey` = ?;");
		$statement->bindValue(1, $value);

		return $statement->execute([$primaryKey]) ? $statement->fetchAll(PDO::FETCH_ASSOC) : null;
	}

	/**
	 * @param string $table
	 * @param string $primaryKey
	 * @param mixed $value
	 */
	protected function _deleteFromTableByPK($table, $primaryKey, $value)
	{
		$statement = $this->getConnection()->prepare("DELETE FROM `$table` WHERE `$primaryKey` = ?;");
		$statement->bindValue(1, $value);
		$statement->execute([$primaryKey]);
	}
}