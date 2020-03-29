<?php /** @noinspection PhpComposerExtensionStubsInspection */
/**
 * Class PdoSQLiteHandler
 */
namespace Moro\Migration\Handler;
use \PDO;

/**
 * Class PdoSQLiteHandler
 * @package Moro\Migration\Handler
 */
class PdoSQLiteHandler extends AbstractPdoHandler
{
	/**
	 * @var string  The name of service for save migration information.
	 */
	protected $_serviceName = 'team-migrations.pdo-sqlite';

	/**
	 * @param string $name
	 * @return bool
	 */
	protected function _isTableExists($name)
	{
		$statement = $this->getConnection()->prepare("SELECT count(*) FROM sqlite_master WHERE type='table' AND name=?");
		return $statement->execute([$name]) ? (bool)$statement->fetch(PDO::FETCH_COLUMN) : false;
	}
}