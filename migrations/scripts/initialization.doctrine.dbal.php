<?php
/** @var $container  \ArrayAccess */
/** @var $service  \Moro\Migration\Handler\DoctrineDBALHandler */
/** @var $arguments *///* Arguments for this script (as GET query).
$schema = $service->getSchema();

if (false === $schema->hasTable($service->getMigrationsTableName()))
{
	$table = $schema->createTable($service->getMigrationsTableName());
	$table->addColumn('name',      'string', ['length' => 64])->setNotnull(true);
	$table->addColumn('type',      'string', ['length' => 10])->setNotnull(true);
	$table->addColumn('created',   'string', ['length' => 19])->setNotnull(true);
	$table->addColumn('applied',   'string', ['length' => 19])->setNotnull(true);
	$table->addColumn('script',    'text')                    ->setNotnull(true);
	$table->addColumn('options',   'text')                    ->setNotnull(false);
	$table->addColumn('signature', 'string', ['length' => 40])->setNotnull(true);
	$table->addUniqueIndex(['name'], 'idx_'.$service->getMigrationsTableName());
}