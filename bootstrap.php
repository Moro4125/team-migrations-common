<?php
/**
 * Bootstrap for run file "bin/migrations.php".
 */
namespace Moro\Migration;
use \Symfony\Component\Console\Application;
use \Symfony\Component\EventDispatcher\EventDispatcher;
use \Moro\Migration\Command\InteractiveCommand;
use \Moro\Migration\Command\MigrationsCreate;
use \Moro\Migration\Command\MigrationsMigrate;
use \Moro\Migration\Command\MigrationsStatus;
use \ArrayObject;

/**
 * Class InteractiveCreate
 * @package Moro\Migration
 */
class InteractiveCreate extends MigrationsCreate
{
	use InteractiveCommand;
}

/**
 * Class InteractiveMigrate
 * @package Moro\Migration
 */
class InteractiveMigrate extends MigrationsMigrate
{
	use InteractiveCommand;
}

/**
 * Class InteractiveStatus
 * @package Moro\Migration
 */
class InteractiveStatus extends MigrationsStatus
{
	use InteractiveCommand;
}

$container = new ArrayObject();
$eventDispatcher = new EventDispatcher();
$migrationManager = new MigrationManager();

$migrationManager->setContainer($container);
$migrationManager->setEventDispatcher($eventDispatcher);

$console = new Application();
$console->add((new InteractiveCreate($migrationManager))->setContainer($container)->setEventDispatcher($eventDispatcher));
$console->add((new InteractiveMigrate($migrationManager))->setContainer($container)->setEventDispatcher($eventDispatcher));
$console->add((new InteractiveStatus($migrationManager))->setContainer($container)->setEventDispatcher($eventDispatcher));

return $console;