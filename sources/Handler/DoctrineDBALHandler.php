<?php
/**
 * Class DoctrineDBALHandler
 */
namespace Moro\Migration\Handler;
use \Doctrine\DBAL\Connection;
use \PDO;
use \Exception;

/**
 * Class DoctrineDBAL
 * @package Moro\Migration\Handler
 */
class DoctrineDBALHandler extends AbstractSqlHandler
{
	/**
	 * @var string  The name of service for save migration information.
	 */
	protected $_serviceName = 'team-migrations.doctrine-dbal';

	/**
	 * @var Connection
	 */
	protected $_connection;

	/**
	 * @var \Doctrine\DBAL\Schema\Schema
	 */
	protected $_schema;

	/**
	 * @param Connection $connection
	 * @return $this
	 */
	public function setConnection(Connection $connection)
	{
		$this->_connection = $connection;
		return $this;
	}

	/**
	 * @return Connection
	 */
	public function getConnection()
	{
		return $this->_connection;
	}

	/**
	 * @return \Doctrine\DBAL\Schema\Schema
	 */
	public function getSchema()
	{
		if (empty($this->_schema))
		{
			$this->_schema = $this->getConnection()->getSchemaManager()->createSchema();
		}

		return $this->_schema;
	}

	/**
	 * @return \Doctrine\DBAL\Query\QueryBuilder
	 */
	public function newQuery()
	{
		return $this->getConnection()->createQueryBuilder();
	}

	/**
	 * @param null|mixed $results
	 */
	protected function _afterCallPhpScript($results = null)
	{
		$connection = $this->getConnection();
		$currentDbSchema = $connection->getSchemaManager()->createSchema();
		$databasePlatform = $connection->getDatabasePlatform();

		foreach ($this->getSchema()->getMigrateFromSql($currentDbSchema, $databasePlatform) as $sql)
		{
			$connection->exec($sql);
		}

		$this->_schema = null;
	}

	/**
	 * @param string $name
	 * @return bool
	 */
	protected function _isTableExists($name)
	{
		return $this->getSchema()->hasTable($name);
	}

	/**
	 * @param string $table
	 * @param array $columns
	 * @param callable $callback
	 */
	protected function _selectFromTable($table, array $columns, callable $callback)
	{
		foreach ($this->newQuery()->select($columns)->from($table)->execute() as $record)
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
		$connection->beginTransaction();

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

		return true;
	}

	/**
	 * @param string $table
	 * @param array $columns
	 * @param callable $callback
	 */
	protected function _insertRecords($table, array $columns, callable $callback)
	{
		$connection = $this->getConnection();
		$query = $this->newQuery()->insert($table)->values(array_fill_keys($columns, '?'))->getSQL();
		$statement = $connection->prepare($query);

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
		$query = $this->newQuery()->update($table);

		foreach ($columns as $col)
		{
			$query->set($col, '?');
		}

		foreach ($where as $col)
		{
			$query->andWhere($col.' = ?');
		}

		$statement = $this->getConnection()->prepare($query->getSQL());

		/** @var \Generator $generator */
		foreach (($generator = $callback()) as $record)
		{
			$record = array_merge(array_slice($record, count($where)), array_slice($record, 0, count($where)));
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
		$query = $this->newQuery()->select('*')->from($table)->where("$primaryKey = ?")->getSQL();
		$statement = $this->getConnection()->prepare($query);
		return $statement->execute([$value]) ? $statement->fetch(PDO::FETCH_ASSOC) : null;
	}

	/**
	 * @param string $table
	 * @param string $primaryKey
	 * @param mixed $value
	 */
	protected function _deleteFromTableByPK($table, $primaryKey, $value)
	{
		$query = $this->newQuery()->delete($table)->where("$primaryKey = ?")->getSQL();
		$this->getConnection()->prepare($query)->execute([$value]);
	}
}