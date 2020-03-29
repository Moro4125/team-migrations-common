<?php /** @noinspection PhpComposerExtensionStubsInspection */
/**
 * Class PdoMySQLHandler
 */
namespace Moro\Migration\Handler;
use \PDO;

/**
 * Class PdoMySQLHandler
 * @package Moro\Migration\Handler
 */
class PdoMySQLHandler extends AbstractPdoHandler
{
	/**
	 * @var string  The name of service for save migration information.
	 */
	protected $_serviceName = 'team-migrations.pdo-mysql';

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
}