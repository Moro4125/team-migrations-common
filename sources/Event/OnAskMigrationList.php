<?php
/**
 * Event class OnAskMigrationList.
 */
namespace Moro\Migration\Event;

/**
 * Class OnAskMigrationList
 * @package Moro\Migration\Event
 */
class OnAskMigrationList extends AbstractEvent
{
	/**
	 * @var array
	 */
	protected $_migrations;

	/**
	 * @param array $list
	 * @return $this
	 */
	public function setMigrations(array $list)
	{
		assert(count($list) == min(count(array_filter($list, 'is_string')), count(array_filter(array_keys($list), 'is_string'))));

		$this->_migrations = $list;
		return $this;
	}

	/**
	 * @return array
	 */
	public function getMigrations()
	{
		return $this->_migrations ?: [];
	}
}