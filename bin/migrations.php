<?php
/**
 * Command line support.
 */
namespace Moro\Migration;
use \Symfony\Component\Console\Application;
use \Symfony\Component\EventDispatcher\EventDispatcher;
use \Moro\Migration\Handler\FilesStorageHandler;
use \Moro\Migration\Command\AbstractCommand;
use \Moro\Migration\Command\MigrationsMigrate;
use \Moro\Migration\Command\MigrationsStatus;
use \ArrayObject;

define('CLI_NAMESPACE_MIGRATIONS', true);

for ($projectPath = $currentPath = dirname(__DIR__); strlen($currentPath) > 3; $currentPath = dirname($currentPath))
{
	file_exists($currentPath.DIRECTORY_SEPARATOR.'composer.json') && $projectPath = $currentPath;
}

/** @noinspection PhpIncludeInspection */
require_once "$projectPath/vendor/autoload.php";

if (file_exists("$projectPath/bootstrap.php"))
{
	/** @noinspection PhpIncludeInspection */
	$console = require "$projectPath/bootstrap.php";

	if ($console instanceof Application)
	{
		$helperSet = $console->getHelperSet();
		$commands = $console->all();

		$console = new Application('Team Migrations', MigrationManager::VERSION);
		$console->setHelperSet($helperSet);

		foreach ($commands as $command)
		{
			if ($command instanceof AbstractCommand)
			{
				$console->add($command);
			}
		}
	}
}

if (empty($console))
{
	$subscriber = new FilesStorageHandler();
	$subscriber->setStoragePath($projectPath.DIRECTORY_SEPARATOR.'storage');

	$container = new ArrayObject();
	$container->offsetSet($subscriber->getServiceName(), $subscriber);

	$eventDispatcher = new EventDispatcher();
	$eventDispatcher->addSubscriber($subscriber);

	$migrationManager = new MigrationManager();
	$migrationManager->setContainer($container);
	$migrationManager->setEventDispatcher($eventDispatcher);

	$console = new Application('Team Migrations', MigrationManager::VERSION);
	$console->add(new MigrationsMigrate($migrationManager));
	$console->add(new MigrationsStatus($migrationManager));
}

$console->run();