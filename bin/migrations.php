<?php
/**
 * Command line support.
 */
namespace Moro\Migration;
use \Symfony\Component\Console\Application;
use \Moro\Migration\Command\AbstractCommand;

global $argv;
define('CLI_NAMESPACE_MIGRATIONS', true);

for ($projectPath = $currentPath = dirname(__DIR__); strlen($currentPath) > 3; $currentPath = dirname($currentPath))
{
	file_exists($currentPath.DIRECTORY_SEPARATOR.'composer.json') && $projectPath = $currentPath;
}

/** @noinspection PhpIncludeInspection */
require_once "$projectPath/vendor/autoload.php";
$bootstrap = file_exists("$projectPath/bootstrap.php")
	? "$projectPath/bootstrap.php"
	: dirname(__DIR__).'/bootstrap.php';

/** @noinspection PhpIncludeInspection */
$console = require $bootstrap;

if ($console instanceof Application)
{
	if (empty($argv[1]) || $argv[1] == 'list')
	{
		$commands = $console->all();
		$console = new Application();

		foreach ($commands as $command)
		{
			if ($command instanceof AbstractCommand)
			{
				$console->add($command);
			}
		}
	}

	$console->setName('Team Migrations');
	$console->setVersion(MigrationManager::VERSION);
	$console->run();
}
else
{
	echo "\nFile \"bootstrap.php\" must return instance of class \"Symfony\\Component\\Console\".\n";
}