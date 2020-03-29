<?php /** @noinspection PhpComposerExtensionStubsInspection */
/**
 * Class DoctrineDBALHandler
 */
namespace Moro\Migration\Handler;
use \Doctrine\DBAL\Connection;
use \Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
     * @return mixed
     * @throws \Doctrine\DBAL\DBALException
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
		return null;
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
     * @return bool|\Exception
     * @throws \Doctrine\DBAL\ConnectionException
     */
	protected function _transaction(callable $callback)
	{
		$connection = $this->getConnection();
		$connection->beginTransaction();

		try
		{
			$callback();
			$connection->isRollbackOnly() ? $connection->rollBack() : $connection->commit();
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
     * @throws \Doctrine\DBAL\DBALException
     */
	protected function _insertRecords($table, array $columns, callable $callback)
	{
		$values = array_combine(
			array_map(function($value) {
				return preg_replace(self::PCRE_COLUMN_NAME, '$2', $value);
			}, $columns),
			array_map(function($value) {
				return preg_replace(self::PCRE_COLUMN_VALUE, '$1?$3', $value);
			}, $columns)
		);

		$connection = $this->getConnection();
		$query = $this->newQuery()->insert($table)->values($values)->getSQL();
		$statement = $connection->prepare($query);

		/** @var \Generator $generator */
		foreach (($generator = $callback()) as $record)
		{
			try
			{
				$statement->execute($record);
				$generator->send($connection->lastInsertId());
			} /** @noinspection PhpRedundantCatchClauseInspection */
			catch (UniqueConstraintViolationException $exception)
			{
				$this->warning('Skip record: '.implode(', ', $record));
				$generator->send(0);
			}
		}
	}

    /**
     * @param string $table
     * @param array $columns
     * @param callable $callback
     * @param array $where
     * @throws \Doctrine\DBAL\DBALException
     */
	protected function _updateRecords($table, array $columns, callable $callback, array $where)
	{
		$query = $this->newQuery()->update($table);

		foreach ($columns as $value)
		{
			$column = preg_replace(self::PCRE_COLUMN_NAME, '$2', $value);
			$value  = preg_replace(self::PCRE_COLUMN_VALUE, '$1?$3', $value);
			$query->set($column, $value);
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
			$generator->send($statement->rowCount());
		}
	}

    /**
     * @param string $table
     * @param null $reserved
     * @param callable $callback
     * @param array $where
     * @throws \Doctrine\DBAL\DBALException
     */
	protected function _deleteRecords($table, $reserved, callable $callback, array $where)
	{
		unset($reserved);
		$query = $this->newQuery()->delete($table);

		foreach ($where as $col)
		{
			$query->andWhere($col.' = ?');
		}

		$statement = $this->getConnection()->prepare($query->getSQL());

		/** @var \Generator $generator */
		foreach (($generator = $callback()) as $record)
		{
			$statement->execute($record);
			$generator->send($statement->rowCount());
		}
	}

    /**
     * @param string $sql
     * @throws \Doctrine\DBAL\DBALException
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
     * @throws \Doctrine\DBAL\DBALException
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
     * @throws \Doctrine\DBAL\DBALException
     */
	protected function _deleteFromTableByPK($table, $primaryKey, $value)
	{
		$query = $this->newQuery()->delete($table)->where("$primaryKey = ?")->getSQL();
		$this->getConnection()->prepare($query)->execute([$value]);
	}
}