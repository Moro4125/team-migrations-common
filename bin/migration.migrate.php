<?php
/**
 * Test for method Moro\Migration\MigrationManager::migrate
 */
namespace Moro\Migration;
use \Symfony\Component\Console\Application;
use \Symfony\Component\Console\Input\ArrayInput;
use \Symfony\Component\EventDispatcher\EventDispatcher;
use \Moro\Migration\Subscriber\ResourceSubscriber;
use \Moro\Migration\Command\MigrationMigrate;
use \ArrayObject;

require "../vendor/autoload.php";
global $argv;

$subscriber = new ResourceSubscriber();
$subscriber->setStoragePath(dirname(__DIR__).DIRECTORY_SEPARATOR.'storage');

$container = new ArrayObject();
$container->offsetSet($subscriber->getServiceName(), $subscriber);

$eventDispatcher = new EventDispatcher();
$eventDispatcher->addSubscriber($subscriber);

$migrationManager = new MigrationManager();
$migrationManager->setContainer($container);
$migrationManager->setEventDispatcher($eventDispatcher);

$console = new Application();
$console->add(new MigrationMigrate($migrationManager));
$console->run(new ArrayInput(array_merge(['migration:migrate'], array_slice($argv, 1))));