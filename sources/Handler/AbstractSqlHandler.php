<?php
/**
 * Class AbstractSqlHandler
 */
namespace Moro\Migration\Handler;
use \Moro\Migration\MigrationManager;
use \Moro\Migration\Event\OnAskMigrationList;
use \Moro\Migration\Event\OnAskMigrationApply;
use \Moro\Migration\Event\OnAskMigrationRollback;
use \Exception;

/**
 * Class AbstractSqlHandler
 * @package Moro\Migration\Handler
 */
abstract class AbstractSqlHandler extends AbstractHandler
{
	const DATE_TIME_FORMAT   = 'Y-m-d H:i:s';

	const COL_NAME      = 'name';
	const COL_TYPE      = 'type';
	const COL_CREATED   = 'created';
	const COL_APPLIED   = 'applied';
	const COL_SCRIPT    = 'script';
	const COL_OPTIONS   = 'options';
	const COL_SIGNATURE = 'signature';

	/**
	 * @var string
	 */
	protected $_migrationsTableName = 'z_team_migrations';

	/**
	 * @var array
	 */
	protected $_newIdListStack = [];

	/**
	 * @param string $name
	 * @return $this
	 */
	public function setMigrationsTableName($name)
	{
		assert(is_string($name));
		$this->_migrationsTableName = $name;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getMigrationsTableName()
	{
		return $this->_migrationsTableName;
	}

	/**
	 * @param OnAskMigrationList $event
	 * @return bool
	 */
	protected function _onAskMigrationList(OnAskMigrationList $event)
	{
		if ($this->_isTableExists($this->_migrationsTableName))
		{
			$list = [];
			$columns = [self::COL_NAME, self::COL_CREATED];

			$this->_selectFromTable($this->_migrationsTableName, $columns, function($record) use (&$list)
			{
				list($module, $file, $step) = explode(':', $record[self::COL_NAME]);
				$name = "$module:$file";

				if (empty($list[$name]))
				{
					$list[$name] = (string)strtotime($record[self::COL_CREATED]);
				}

				if ((int)$step)
				{
					$list[$name] .= '|' . $step;
				}
			});

			$event->setMigrations($list);
		}

		return true;
	}

	/**
	 * @param OnAskMigrationApply $event
	 * @return bool
	 */
	protected function _onAskMigrationApply(OnAskMigrationApply $event)
	{
		$result = $this->_transaction(function() use ($event)
		{
			if ($step = $event->getStep())
			{
				switch ($event->getType())
				{
					// Import from CSV file. Require query parameter "table" in CSV file name in INI file.
					case 'csv':
						$records = str_getcsv($event->getScript(), "\n", "\x01");
						$columns = array_map('trim', str_getcsv(array_shift($records)));
						$records = array_map('str_getcsv', $records);

						$iLength = count($columns);
						$sqlName = @$event->getArguments()['table'];
						$results = [$sqlName];

						$this->_insertRecords($sqlName, $columns, function() use (&$results, $records, $iLength)
						{
							$stackSize = count($this->_newIdListStack);
							$stackHead = [];

							try
							{
								foreach ($records as $item)
								{
									$item = array_map('trim', $item);
									$values = [];

									for ($i = 0; $i < $iLength; $i++)
									{
										if (isset($item[$i]) && preg_match('~^(\\$+)(\\d+)$~', $item[$i], $match))
										{
											$stackPos = $stackSize - strlen($match[1]);
											$rowIdPos = (int)$match[2] - 1;

											if (empty($this->_newIdListStack[$stackPos][$rowIdPos]))
											{
												continue 2;
											}

											$item[$i] = $this->_newIdListStack[$stackPos][$rowIdPos];
										}

										$values[] = isset($item[$i]) ? $item[$i] : '';
									}

									$id = (yield $values);
									$results[] = $id;
									$stackHead[] = $id;

									yield null;
								}
							}
							finally
							{
								$this->_newIdListStack[] = $stackHead;
							}
						});

						$event->setResultsOfCall($results);
						break;

					// Execute custom SQL script.
					case 'sql':
						$this->_executeSql(preg_replace_callback('~\{\{(.*?)\}\}~', function($match)
							{
								$methodName = 'get'.implode('', array_map('ucfirst', explode('.', $match[1])));
								return method_exists($this, $methodName) ? $this->{$methodName}() : $match[0];
							},
							$event->getScript())
						);
						break;

					// Call PHP script with this object as argument "service".
					case 'php':
						$event->callPhpScript();
						break;

					// If detected unknown migration type.
					default:
						$event->setException(new Exception('Unknown migration type.'));
				}
			}
			// Commit information about migration and rollback scripts to migration table.
			else
			{
				$columns = [
					self::COL_NAME,
					self::COL_TYPE,
					self::COL_CREATED,
					self::COL_APPLIED,
					self::COL_SCRIPT,
					self::COL_OPTIONS,
					self::COL_SIGNATURE,
				];

				try
				{
					$list = $event->getRollback();
					$list[0] = [
						MigrationManager::ROLLBACK_KEY_ARGS => null,
						MigrationManager::ROLLBACK_KEY_TYPE => $event->getType(),
						MigrationManager::ROLLBACK_KEY_CODE => $event->getScript(),
						MigrationManager::ROLLBACK_KEY_SIGN => $event->getHash(0),
					];

					$this->_insertRecords($this->_migrationsTableName, $columns, function() use ($event, $list) {
						$migrationTime = date(self::DATE_TIME_FORMAT, $event->getTime());

						foreach ($list as $step => $record)
						{
							$values = [
								$event->getMigrationName() . ':' . $step,
								$record[MigrationManager::ROLLBACK_KEY_TYPE],
								$migrationTime,
								date(self::DATE_TIME_FORMAT, time()),
								$record[MigrationManager::ROLLBACK_KEY_CODE],
								$record[MigrationManager::ROLLBACK_KEY_ARGS],
								$record[MigrationManager::ROLLBACK_KEY_SIGN]
							];

							yield $values;
							yield;
						}
					});
				}
				finally
				{
					$this->_newIdListStack = [];
				}
			}
		});

		$result instanceof Exception && $event->setException($result);
	}

	/**
	 * @param OnAskMigrationRollback $event
	 * @return bool
	 */
	protected function _onAskMigrationRollback(OnAskMigrationRollback $event)
	{
		$result = $this->_transaction(function() use ($event)
		{
			$step = $event->getStep();
			$name = $event->getMigrationName() . ':' . $step;

			if ($step && $rec = $this->_selectFromTableByPK($this->_migrationsTableName, self::COL_NAME, $name))
			{
				switch ($rec[self::COL_TYPE])
				{
					// Execute custom SQL script.
					case 'sql':
						if ($event->validateScript($rec[self::COL_SIGNATURE], 'sql', $rec[self::COL_SCRIPT]))
						{
							$this->_executeSql($rec[self::COL_SCRIPT]);
						}
						else
						{
							$event->setException(new Exception("Wrong script signature: ".$rec[self::COL_OPTIONS]));
						}
						break;

					// Call PHP script with this object as argument "service".
					case 'php':
						$event->callPhpScript($rec[self::COL_SIGNATURE],$rec[self::COL_SCRIPT],$rec[self::COL_OPTIONS]);
						break;

					// If detected unknown migration type.
					default:
						$event->setException(new Exception("Unknown migration type: ".$rec[self::COL_TYPE]));
				}
			}

			$this->_deleteFromTableByPK($this->_migrationsTableName, self::COL_NAME, $name);
		});

		$result instanceof Exception && $event->setException($result);
	}

	/**
	 * @param string $name
	 * @return bool
	 */
	abstract protected function _isTableExists($name);

	/**
	 * @param string $table
	 * @param array $columns
	 * @param callable $callback
	 */
	abstract protected function _selectFromTable($table, array $columns, callable $callback);

	/**
	 * @param callable $callback
	 * @return null|\Exception
	 */
	abstract protected function _transaction(callable $callback);

	/**
	 * @param string $table
	 * @param array $columns
	 * @param callable $callback
	 */
	abstract protected function _insertRecords($table, array $columns, callable $callback);

	/**
	 * @param string $sql
	 */
	abstract protected function _executeSql($sql);

	/**
	 * @param string $table
	 * @param string $primaryKey
	 * @param mixed $value
	 * @return array|null
	 */
	abstract protected function _selectFromTableByPK($table, $primaryKey, $value);

	/**
	 * @param string $table
	 * @param string $primaryKey
	 * @param mixed $value
	 */
	abstract protected function _deleteFromTableByPK($table, $primaryKey, $value);
}