<?php /** @noinspection PhpComposerExtensionStubsInspection */
/**
 * Class PdoPostgreSQLHandler
 */
namespace Moro\Migration\Handler;
use \PDO;

/**
 * Class PdoPostgreSQLHandler
 * @package Moro\Migration\Handler
 */
class PdoPostgreSQLHandler extends AbstractPdoHandler
{
	/**
	 * @var string  The name of service for save migration information.
	 */
	protected $_serviceName = 'team-migrations.pdo-postgresql';

	/**
	 * @param string $name
	 * @return bool
	 */
	protected function _isTableExists($name)
	{
		$connection = $this->getConnection();
		$statement = $connection->prepare('SHOW search_path;');
		$schemas = explode(',', $statement->execute() ? $statement->fetch(PDO::FETCH_COLUMN) : '');

		$sql = "SELECT quote_ident(table_name) FROM information_schema.tables WHERE table_schema = ? AND table_name = ? AND table_type != 'VIEW'";
		$statement = $connection->prepare($sql);

		// @todo What to do if schema is '"$user"'
		foreach ($schemas as $schema)
		{
			if ($statement->execute([$schema, $name]) && $statement->fetchAll())
			{
				return true;
			}
		}

		return false;
	}
}